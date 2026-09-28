<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sondes d'exploitation : que se passe-t-il RÉELLEMENT en ce moment.
 *
 * L'audit ({@see \App\Console\Commands\AuditCommand}) lit le code et la
 * configuration ; ces sondes lisent l'état du système en marche. Les deux se
 * complètent : un préfixe peut être correct dans le code et faux dans les
 * données, une tâche peut être planifiée et ne plus tourner.
 *
 * Le service rend des constats structurés, consommés à la fois par la commande
 * `tonji:sante` et par l'API du back-office. Une seule définition des règles,
 * deux restitutions.
 */
final class Sante
{
    public const OK = 'ok';
    public const ALERTE = 'alerte';       // à regarder, sans urgence
    public const CRITIQUE = 'critique';   // quelque chose est cassé maintenant

    /**
     * @return array{
     *   etat: string,
     *   genere_a: string,
     *   environnement: string,
     *   controles: list<array{cle:string, titre:string, etat:string, detail:string, mesures?: array<string,mixed>}>
     * }
     */
    public function rapport(): array
    {
        $controles = [
            ...$this->taches(),
            $this->paiementsEnSouffrance(),
            $this->decaissementsARegulariser(),
            ...$this->reglages(),
            $this->schema(),
        ];

        // L'état global est celui du contrôle le plus grave : un tableau vert
        // avec une ligne rouge doit s'afficher rouge.
        $etat = self::OK;
        foreach ($controles as $c) {
            if ($c['etat'] === self::CRITIQUE) {
                $etat = self::CRITIQUE;
                break;
            }
            if ($c['etat'] === self::ALERTE) {
                $etat = self::ALERTE;
            }
        }

        return [
            'etat'          => $etat,
            'genere_a'      => now()->toIso8601String(),
            'environnement' => config('app.env'),
            'controles'     => $controles,
        ];
    }

    /**
     * Chaque tâche planifiée a-t-elle donné signe de vie dans le délai toléré ?
     *
     * C'est la sonde qui rend visible une entrée crontab supprimée ou un
     * planificateur arrêté — la panne la plus silencieuse du produit.
     *
     * @return list<array<string, mixed>>
     */
    private function taches(): array
    {
        $table = project_table('taches');
        if (! Schema::hasTable($table)) {
            return [[
                'cle' => 'taches', 'titre' => 'Tâches planifiées', 'etat' => self::ALERTE,
                'detail' => 'Le suivi des tâches n\'est pas installé : jouez le script SQL 032.',
            ]];
        }

        $vues = DB::table($table)->get()->keyBy('commande');
        $constats = [];

        foreach (Registre::TACHES as $commande => $cadence) {
            $tolere = Registre::RETARD_TOLERE[$commande] ?? 93600;
            $ligne = $vues[$commande] ?? null;

            if (! $ligne) {
                $constats[] = [
                    'cle' => "tache:{$commande}", 'titre' => $commande, 'etat' => self::ALERTE,
                    'detail' => "Aucun passage enregistré pour l'instant ({$cadence}).",
                ];
                continue;
            }

            $silence = now()->diffInSeconds($ligne->derniere_execution, true);
            $depuis = $this->humaniser((int) $silence);

            if ($silence > $tolere) {
                $constats[] = [
                    'cle' => "tache:{$commande}", 'titre' => $commande, 'etat' => self::CRITIQUE,
                    'detail' => "Silencieuse depuis {$depuis} — attendue {$cadence}.",
                    'mesures' => ['derniere_execution' => $ligne->derniere_execution, 'passages' => $ligne->passages],
                ];
                continue;
            }

            if ($ligne->statut === 'echec') {
                $constats[] = [
                    'cle' => "tache:{$commande}", 'titre' => $commande, 'etat' => self::ALERTE,
                    'detail' => "Dernier passage en échec il y a {$depuis} : " . ($ligne->message ?? 'sans message'),
                ];
                continue;
            }

            $constats[] = [
                'cle' => "tache:{$commande}", 'titre' => $commande, 'etat' => self::OK,
                'detail' => "Dernier passage il y a {$depuis} ({$cadence}).",
                'mesures' => ['passages' => $ligne->passages, 'echecs' => $ligne->echecs],
            ];
        }

        return $constats;
    }

    /**
     * Encaissements restés « initié » : soit le cotisant n'a pas validé, soit
     * une confirmation s'est perdue. Au-delà d'une heure, c'est la seconde
     * hypothèse qu'il faut vérifier.
     *
     * @return array<string, mixed>
     */
    private function paiementsEnSouffrance(): array
    {
        $table = project_table('payin');
        if (! Schema::hasTable($table)) {
            return ['cle' => 'payin', 'titre' => 'Encaissements', 'etat' => self::ALERTE, 'detail' => 'Table absente.'];
        }

        $anciens = DB::table($table)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subHour())
            ->where('date_creation', '>', now()->subDays(3))
            ->count();

        return [
            'cle' => 'payin', 'titre' => 'Encaissements en attente', 'mesures' => ['en_attente' => $anciens],
            'etat' => $anciens > 5 ? self::ALERTE : self::OK,
            'detail' => $anciens === 0
                ? 'Aucun encaissement bloqué depuis plus d\'une heure.'
                : "{$anciens} encaissement(s) en attente depuis plus d'une heure. La réconciliation les reprend toutes les 5 minutes.",
        ];
    }

    /**
     * Décaissements à régulariser : un échec dont le solde n'a pas été rendu,
     * ou une sortie restée « en cours » parce que l'opérateur n'a jamais
     * répondu. Dans les deux cas, de l'argent est immobilisé.
     *
     * @return array<string, mixed>
     */
    private function decaissementsARegulariser(): array
    {
        $table = project_table('payout');
        if (! Schema::hasTable($table)) {
            return ['cle' => 'payout', 'titre' => 'Décaissements', 'etat' => self::ALERTE, 'detail' => 'Table absente.'];
        }

        $bloques = DB::table($table)
            ->where(function ($q) {
                $q->whereIn('statut', ['initie', 'en_cours'])
                    ->orWhere(function ($q2) {
                        // Un échec compensé porte le marqueur solde_restaure ;
                        // les autres attendent une reprise à la main.
                        $q2->where('statut', 'echec')
                            ->whereRaw("coalesce((response->>'solde_restaure')::boolean, false) = false");
                    });
            })
            ->where('date_creation', '>', now()->subDays(30))
            ->count();

        return [
            'cle' => 'payout', 'titre' => 'Décaissements à régulariser', 'mesures' => ['a_regulariser' => $bloques],
            'etat' => $bloques > 0 ? self::ALERTE : self::OK,
            'detail' => $bloques === 0
                ? 'Aucun décaissement en souffrance sur 30 jours.'
                : "{$bloques} décaissement(s) à régulariser depuis 30 jours. À reprendre depuis l'écran de réconciliation.",
        ];
    }

    /**
     * Réglages sans lesquels une fonction tombe en silence, et réglages dont
     * la valeur par défaut est dangereuse.
     *
     * @return list<array<string, mixed>>
     */
    private function reglages(): array
    {
        $manquants = [];
        foreach (Registre::REGLAGES_CRITIQUES as $cle => $raison) {
            if (blank(config($cle))) {
                $manquants[] = "{$cle} — {$raison}";
            }
        }

        $dangereux = [];
        foreach (Registre::REGLAGES_SUSPECTS as $cle => $regle) {
            if (config($cle) === $regle['interdit']) {
                $dangereux[] = "{$cle} vaut « {$regle['interdit']} » — {$regle['raison']}";
            }
        }

        return [
            [
                'cle' => 'reglages', 'titre' => 'Réglages critiques',
                'etat' => $manquants === [] ? self::OK : self::CRITIQUE,
                'detail' => $manquants === []
                    ? 'Tous renseignés.'
                    : implode(' · ', $manquants),
            ],
            [
                'cle' => 'reglages_suspects', 'titre' => 'Valeurs par défaut dangereuses',
                'etat' => $dangereux === [] ? self::OK : self::ALERTE,
                'detail' => $dangereux === []
                    ? 'Aucune valeur par défaut problématique.'
                    : implode(' · ', $dangereux),
            ],
        ];
    }

    /**
     * Les colonnes attendues existent-elles dans la base connectée ? Un script
     * SQL oublié se voit ici, pas au moment où un utilisateur clique.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $manquantes = [];
        foreach (Registre::SCHEMA_ATTENDU as $suffixe => $colonnes) {
            $table = project_table($suffixe);
            if (! Schema::hasTable($table)) {
                $manquantes[] = "table {$table}";
                continue;
            }
            foreach ($colonnes as $colonne) {
                if (! Schema::hasColumn($table, $colonne)) {
                    $manquantes[] = "{$table}.{$colonne}";
                }
            }
        }

        return [
            'cle' => 'schema', 'titre' => 'Schéma de la base',
            'etat' => $manquantes === [] ? self::OK : self::CRITIQUE,
            'detail' => $manquantes === []
                ? count(Registre::SCHEMA_ATTENDU) . ' tables conformes au registre.'
                : 'Manquant : ' . implode(', ', $manquantes) . '. Un script SQL n\'a pas été joué.',
        ];
    }

    /** Durée lisible : « 3 min », « 2 h 15 », « 3 j ». */
    private function humaniser(int $secondes): string
    {
        if ($secondes < 90) {
            return "{$secondes} s";
        }
        if ($secondes < 5400) {
            return floor($secondes / 60) . ' min';
        }
        if ($secondes < 172800) {
            $heures = floor($secondes / 3600);
            $minutes = floor(($secondes % 3600) / 60);

            return $minutes > 0 ? "{$heures} h {$minutes}" : "{$heures} h";
        }

        return floor($secondes / 86400) . ' j';
    }
}
