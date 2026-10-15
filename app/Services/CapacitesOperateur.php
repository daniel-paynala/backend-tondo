<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ce que Tonji sait faire, pays par pays et opérateur par opérateur.
 *
 * Le système a été écrit en supposant « Airtel Money Gabon » partout. Ouvrir un
 * second pays oblige à rendre cette hypothèse explicite : certaines
 * intégrations existeront, d'autres non, et il faut pouvoir **se dégrader
 * proprement** au lieu de tomber — ou pire, d'encaisser de l'argent qu'on ne
 * saura pas reverser.
 *
 * **L'absence vaut FAUX.** Un opérateur dont personne n'a décrit les capacités
 * n'en a aucune. C'est le seul défaut sûr : supposer qu'une API existe fait
 * collecter sans pouvoir rendre, et l'erreur se découvre au moment du retrait,
 * quand il est trop tard.
 *
 * Cinquième service de la famille, après {@see SortiesAutorisees},
 * {@see CollecteAutorisee}, {@see CompteSuspendu} et {@see PlafondCollecte} —
 * et pour la même raison : une règle recopiée finit toujours par être oubliée
 * sur un canal. Il y en a eu quatre cas ce mois-ci.
 */
class CapacitesOperateur
{
    /**
     * Les capacités reconnues.
     *
     * `kyc`                 — l'opérateur expose une vérification d'identité.
     * `verification_numero` — on peut confirmer le titulaire d'un numéro.
     * `payin`               — on sait encaisser.
     * `payout`              — on sait reverser. La plus critique : sans elle,
     *                         l'argent entre et ne ressort pas.
     * `frais`               — une grille de frais existe pour cet opérateur.
     * `plafonds`            — ses limites de transaction sont connues.
     */
    public const CONNUES = [
        'kyc', 'verification_numero', 'payin', 'payout', 'frais', 'plafonds',
        // Le septième n'est pas une capacité de l'intégration mais une EXIGENCE
        // que nous posons : demander l'adresse à l'inscription. Il vit ici
        // parce qu'il se règle au même endroit et pour le même couple
        // opérateur/pays, et parce qu'une exigence oubliée doit valoir « non »
        // comme le reste.
        //
        // Il n'a rien à voir avec l'envoi du code par courriel, qui se fait
        // dès qu'une adresse est connue. Celui-ci ne décide que de
        // l'APPARITION du champ dans le formulaire.
        'email_obligatoire',
    ];

    /** Aucune capacité — l'état d'un opérateur dont on ne sait rien. */
    /**
     * Le registre vide : toutes les clés connues à faux.
     *
     * Dérivé de CONNUES plutôt que recopié. La liste existait en double, et
     * ajouter une septième capacité d'un seul côté a cassé dix-neuf tests :
     * le cumul lisait une clé que ce tableau n'avait pas. Une liste en double
     * finit toujours par diverger — autant qu'il n'y en ait qu'une.
     *
     * @return array<string, bool>
     */
    private static function aucune(): array
    {
        return array_fill_keys(self::CONNUES, false);
    }

    /** @var array<string, array<string, bool>> Mémoire d'instance. */
    private array $cache = [];

    /**
     * Capacités d'un couple opérateur/pays.
     *
     * @return array<string, bool>
     */
    public function pour(string $projectId, ?string $operateur, ?string $pays): array
    {
        if ($operateur === null || $pays === null) {
            return self::aucune();
        }

        $cle = "{$projectId}|{$operateur}|{$pays}";

        return $this->cache[$cle] ??= $this->lire($projectId, $operateur, $pays);
    }

    /** Vrai si cette capacité précise est disponible. */
    public function a(string $projectId, ?string $operateur, ?string $pays, string $capacite): bool
    {
        return $this->pour($projectId, $operateur, $pays)[$capacite] ?? false;
    }

    /**
     * Capacités du pays d'un utilisateur, tous opérateurs confondus.
     *
     * Une capacité est acquise au pays dès qu'UN opérateur la porte : si Orange
     * sait encaisser au Sénégal, le pays sait encaisser. C'est la bonne maille
     * pour décider si un écran s'ouvre ; le choix de l'opérateur vient après,
     * au moment de payer.
     *
     * @return array<string, bool>
     */
    public function pourLePays(string $projectId, ?string $pays): array
    {
        if ($pays === null) {
            return self::aucune();
        }

        $cle = "{$projectId}|*|{$pays}";

        if (isset($this->cache[$cle])) {
            return $this->cache[$cle];
        }

        $lignes = DB::table(project_table('project_config'))
            ->where('project_id', $projectId)
            ->where('pays', $pays)
            ->where(function ($q) {
                $q->whereNull('actif')->orWhere('actif', true);
            })
            ->pluck('capacites');

        $cumul = self::aucune();
        foreach ($lignes as $brut) {
            foreach ($this->decoder($brut) as $capacite => $valeur) {
                $cumul[$capacite] = $cumul[$capacite] || $valeur;
            }
        }

        return $this->cache[$cle] = $cumul;
    }

    /**
     * Message de refus si ce pays ne peut pas accueillir une nouvelle collecte.
     *
     * **Le contrôle est à la CRÉATION, pas au reversement**, et c'est tout
     * l'enjeu. Une collecte qui encaisse sans pouvoir reverser piège l'argent
     * de ses cotisants : le refus arriverait au moment du retrait, quand le
     * bénéficiaire a déjà collecté et attend son dû. On ne peut pas revenir en
     * arrière à ce moment-là.
     *
     * `payout` suffit donc à décider. Une collecte sans encaissement local est
     * inutile mais inoffensive ; sans décaissement, elle est un piège.
     */
    public function refusPourCreation(string $projectId, ?string $pays): ?string
    {
        if ($pays === null) {
            // Les comptes dont le numéro n'a pas permis de déduire un pays.
            // Les rattacher au Gabon par défaut les ferait entrer dans le
            // mauvais écosystème ; mieux vaut un refus qui mène au support.
            return 'Votre compte n\'est rattaché à aucun pays. Contactez le support.';
        }

        if (! $this->pourLePays($projectId, $pays)['payout']) {
            // Le motif réel — aucune API de décaissement — ne regarde pas
            // l'utilisateur. Ce qu'il doit savoir, c'est que ce n'est pas
            // encore ouvert chez lui.
            return 'Les collectes ne sont pas encore disponibles dans votre pays.';
        }

        return null;
    }

    /** @return array<string, bool> */
    private function lire(string $projectId, string $operateur, string $pays): array
    {
        $brut = DB::table(project_table('project_config'))
            ->where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->value('capacites');

        return $this->decoder($brut);
    }

    /**
     * Normalise ce que porte la colonne.
     *
     * Chaque capacité absente du JSON vaut faux : une description partielle ne
     * doit jamais faire croire à une capacité qu'on n'a pas décrite.
     *
     * @return array<string, bool>
     */
    private function decoder(mixed $brut): array
    {
        if ($brut === null) {
            return self::aucune();
        }

        $decode = is_array($brut) ? $brut : json_decode((string) $brut, true);
        if (! is_array($decode)) {
            return self::aucune();
        }

        $capacites = self::aucune();
        foreach (self::CONNUES as $capacite) {
            $capacites[$capacite] = ($decode[$capacite] ?? false) === true;
        }

        return $capacites;
    }
}
