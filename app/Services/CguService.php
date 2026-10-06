<?php

namespace App\Services;

use App\Support\Taux;

/**
 * Génère les conditions d'utilisation à partir de la configuration opérateur.
 *
 * Les CGU annonçaient jusqu'ici des chiffres écrits en dur dans cinq écrans
 * (app et web), qui ont divergé de la config : elles affirmaient encore que les
 * frais de retrait étaient à la charge du cotisant alors que la matrice
 * `frais_retrait` vaut 0 depuis le 31 août 2026. Le texte est désormais produit
 * ici, à partir de la config réellement appliquée par le calcul des frais.
 *
 * Une seule source de vérité, et le texte suit l'opérateur : le jour où un
 * second opérateur arrive avec une autre grille, rien à toucher côté client.
 *
 * Deux niveaux, conformément à la RÈGLE 4-bis :
 *  – `resume` : les puces affichées directement à l'écran de création ;
 *  – `blocs`  : le détail déplié par le bouton « + », et le document complet
 *               consultable en permanence dans les paramètres.
 */
class CguService
{
    public function __construct(
        private readonly TondoConfigService $config,
    ) {
    }

    /**
     * Construit les CGU applicables à [$operateur] pour [$projectId].
     *
     * @return array{version: string, operateur: string, resume: list<string>, blocs: list<array{titre: string, corps: string}>}
     */
    public function pour(string $projectId, string $operateur = 'airtel', string $pays = 'GA'): array
    {
        return $this->construire(
            $this->config->getOperatorConfig($projectId, $operateur, $pays),
            $operateur,
        );
    }

    /**
     * Partie pure : construit le texte à partir d'une config déjà chargée.
     *
     * Séparée de {@see pour()} pour être testable sans base de données — c'est
     * ici que vit la logique qui a diverge par le passe.
     *
     * @param  array<string, mixed> $cfg
     * @return array{version: string, operateur: string, resume: list<string>, blocs: list<array{titre: string, corps: string}>}
     */
    public function construire(array $cfg, string $operateur = 'airtel'): array
    {
        $plafondEnvoi       = $this->fcfa((int) ($cfg['plafond_par_envoi'] ?? 0));
        $plafondParticulier = $this->fcfa((int) ($cfg['plafond_cagnotte_particulier'] ?? 0));
        $plafondAssociation = $this->fcfa((int) ($cfg['plafond_cagnotte_association'] ?? 0));

        $resume = $this->resume($cfg, $plafondEnvoi);
        $blocs  = $this->blocs($cfg, $plafondEnvoi, $plafondParticulier, $plafondAssociation);

        return [
            // Empreinte du texte RENDU, pas de la config : une reformulation la
            // fait changer, et un réglage qui n'apparaît nulle part ne la fait
            // PAS changer — on ne demande pas de réaccepter pour rien.
            'version'   => $this->version($resume, $blocs),
            'operateur' => $operateur,
            'resume'    => $resume,
            'blocs'     => $blocs,
        ];
    }

    /**
     * Puces du résumé court, affichées sans dépliage à la création d'une collecte.
     *
     * @return list<string>
     */
    private function resume(array $cfg, string $plafondEnvoi): array
    {
        return [
            'Le montant collecté est automatiquement transféré sur le numéro de retrait indiqué.',
            'Ce numéro ne peut plus être modifié après la création de la collecte.',
            // La phrase disait « les frais sont à la charge du cotisant », ce
            // qui est devenu faux : Tonji ne prélève plus sur les cotisations.
            // Elle est produite depuis la config, pour ne plus pouvoir mentir.
            $this->phraseCotisation($cfg),
            "Chaque paiement est plafonné à {$plafondEnvoi}.",
            "Tonji n'arbitre pas les conflits entre membres, sauf cas manifestement clair.",
        ];
    }

    /** Ce que coûte une cotisation, dit depuis la config. */
    private function phraseCotisation(array $cfg): string
    {
        $taux = (float) ($cfg['commission_paynala'] ?? 0);

        return $taux > 0
            ? 'Une commission est appliquée au moment du paiement, à la charge du cotisant.'
            : 'Les cotisations sont sans frais : le cotisant paie exactement le montant qu\'il donne.';
    }

    /**
     * Sections détaillées.
     *
     * @return list<array{titre: string, corps: string}>
     */
    private function blocs(
        array  $cfg,
        string $plafondEnvoi,
        string $plafondParticulier,
        string $plafondAssociation,
    ): array {
        return [
            [
                'titre' => 'Frais',
                'corps' => $this->modeleEconomique($cfg),
            ],
            [
                'titre' => 'Plafonds',
                'corps' => "Chaque paiement est limité à {$plafondEnvoi}. Le total collecté par une "
                    . "collecte est plafonné à {$plafondParticulier} pour un compte particulier et à "
                    . "{$plafondAssociation} pour une association. Un plafond supérieur peut être "
                    . 'accordé au cas par cas, sur justificatif.',
            ],
            [
                'titre' => 'Numéro de retrait',
                'corps' => 'Le numéro de retrait ne pourra plus être changé après la création de la '
                    . 'collecte. Cette règle protège tous les membres contre la fraude.',
            ],
            [
                'titre' => 'Cagnottes publiques',
                'corps' => 'Une collecte est privée par défaut : seuls les membres invités y '
                    . 'participent. Une collecte publique, ouverte à tous, est réservée aux '
                    . 'associations dont le dossier a été validé, et passe en modération avant '
                    . "d'être publiée.",
            ],
            [
                'titre' => 'Différends entre membres',
                'corps' => "Tonji facilite la collecte mais n'arbitre pas les conflits entre cotisants "
                    . 'et bénéficiaires, sauf cas manifestement clair (ex : usurpation d\'identité). '
                    . 'Vous restez responsable du choix de vos co-membres.',
            ],
        ];
    }

    /**
     * Phrase du modèle économique — le cœur du problème que ce service résout.
     *
     * Le TAUX de commission n'est volontairement pas cité : la RÈGLE 4-bis
     * interdit d'exposer le détail du calcul des frais. Le texte dit qui les
     * supporte, pas combien ils valent.
     *
     * La mention des frais de retrait n'apparaît QUE s'ils sont effectivement
     * répercutés sur le cotisant quelque part dans la matrice. À 0 partout, le
     * texte dit qu'ils sont à la charge du bénéficiaire, ce qui est le cas.
     */
    private function modeleEconomique(array $cfg): string
    {
        // Quatre postes, quatre phrases, toutes produites depuis la config.
        // Aucune n'est écrite en dur : c'est précisément parce que ces chiffres
        // vivaient en dur dans cinq écrans qu'ils avaient fini par diverger.
        $lignes = [];

        // 1. Cotisation.
        $lignes[] = $this->phraseCotisation($cfg);

        // 2. Paiement d'un marchand depuis une cagnotte.
        $marchand = (float) ($cfg['frais_marchand'] ?? 0);
        $lignes[] = $marchand > 0
            ? 'Le paiement d\'un commerce depuis une collecte est facturé '
                . $this->pourcentage($marchand) . ' du montant payé.'
            : 'Le paiement d\'un commerce depuis une collecte est sans frais.';

        // 3. Transfert du solde vers un numéro.
        $lignes[] = $this->fraisRetraitRepercutes($cfg)
            ? 'Le transfert du solde vers un numéro Mobile Money est facturé selon le barème '
                . 'en vigueur, indiqué avant validation.'
            : 'Le transfert du solde vers un numéro Mobile Money est sans frais de notre part.';

        // 4. Retrait en espèces — barème de l'opérateur, pas le nôtre.
        $lignes[] = 'Le retrait de l\'argent en espèces est facturé par l\'opérateur selon son '
            . 'propre barème : ' . $this->baremeRetrait($cfg) . '. Ces frais ne reviennent pas '
            . 'à Tonji.';

        $lignes[] = 'Les frais éventuellement prélevés par votre opérateur Mobile Money au moment '
            . 'du paiement lui sont propres et ne reviennent pas à Tonji.';

        return implode(' ', $lignes);
    }

    /**
     * Barème de retrait en espèces, rendu depuis les tranches de l'opérateur.
     *
     * Les tranches sont la source : une renégociation avec Airtel change le
     * texte des conditions sans qu'une ligne de code ne bouge.
     */
    private function baremeRetrait(array $cfg): string
    {
        $morceaux = [];

        foreach (($cfg['tranches'] ?? []) as $tranche) {
            $valeur = (float) ($tranche['valeur'] ?? 0);
            $max    = (int) ($tranche['montant_max'] ?? 0);

            $morceaux[] = ($tranche['type'] ?? '') === 'pourcentage'
                ? $this->pourcentage($valeur) . ' jusqu\'à ' . $this->fcfa($max)
                : $this->fcfa((int) $valeur) . ' au-delà';
        }

        return $morceaux === []
            ? 'selon le barème affiché par l\'opérateur'
            : implode(', puis ', $morceaux);
    }

    /** Vrai si un seul couple (type de collecte × type de compte) répercute des frais de retrait. */
    private function fraisRetraitRepercutes(array $cfg): bool
    {
        foreach (($cfg['frais_retrait'] ?? []) as $parTypeCompte) {
            foreach ((array) $parTypeCompte as $taux) {
                if ((float) $taux > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Empreinte du texte effectivement affiché.
     *
     * Hacher le TEXTE plutôt que la config a deux conséquences voulues : une
     * reformulation fait changer la version — les conditions ont bien changé
     * pour l'utilisateur —, et un réglage qui n'apparaît nulle part, comme le
     * taux de commission, ne la fait pas changer.
     *
     * @param  list<string> $resume
     * @param  list<array{titre: string, corps: string}> $blocs
     */
    private function version(array $resume, array $blocs): string
    {
        return substr(md5(json_encode([$resume, $blocs], JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    /** 0.02 → « 2 % ». Délégué à {@see Taux}, partagé avec le bot WhatsApp. */
    private function pourcentage(float $taux): string
    {
        return Taux::pourcentage($taux);
    }

    /** 500000 → « 500 000 FCFA ». */
    private function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ') . ' FCFA';
    }
}
