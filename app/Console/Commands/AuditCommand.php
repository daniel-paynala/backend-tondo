<?php

namespace App\Console\Commands;

use App\Support\Registre;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit d'intégrité du produit.
 *
 * Répond à une question simple : « est-ce que ce qui tourne correspond à ce
 * qu'on croit avoir écrit ? » Les contrôles statiques (préfixes, drapeaux,
 * tâches, réglages) n'ont besoin de rien ; l'option --donnees ajoute ceux qui
 * interrogent la base réellement connectée.
 *
 * Code de sortie non nul dès qu'une anomalie est trouvée : la commande est
 * faite pour tourner en intégration continue, pas seulement à la main.
 */
class AuditCommand extends Command
{
    protected $signature = 'tonji:audit
                            {--donnees : Ajoute les contrôles qui interrogent la base de données}
                            {--strict : Traite aussi les avertissements comme des anomalies}';

    protected $description = 'Vérifie la cohérence des préfixes, drapeaux, tâches planifiées et réglages';

    /** @var list<array{niveau:string, controle:string, message:string}> */
    private array $constats = [];

    public function handle(): int
    {
        $this->info('Audit Tonji — ' . config('app.env') . ' — ' . now()->format('d/m/Y H:i'));
        $this->newLine();

        $this->controlerPrefixes();
        $this->controlerDrapeaux();
        $this->controlerTaches();
        $this->controlerReglages();
        $this->controlerVersions();

        if ($this->option('donnees')) {
            $this->controlerSchema();
            $this->controlerDeriveDonnees();
        } else {
            $this->ajouter('ignore', 'données', 'Contrôles en base non exécutés (ajoutez --donnees).');
        }

        return $this->restituer();
    }

    // ── Contrôles statiques ─────────────────────────────────────────────────

    /**
     * Aucun préfixe banni, et aucun préfixe maison qui ne soit pas déclaré au
     * registre. C'est le contrôle qui aurait arrêté « TONDOPAYIN » le jour où
     * il a été écrit.
     */
    private function controlerPrefixes(): void
    {
        $autorises = Registre::prefixesAutorises();
        $trouves = [];

        foreach ($this->fichiersPhp() as $chemin) {
            $contenu = file_get_contents($chemin) ?: '';
            preg_match_all("/'((?:TONJI|TONDO)[A-Z-]{2,})'/", $contenu, $m, PREG_OFFSET_CAPTURE);

            $lignes = explode("\n", $contenu);
            foreach ($m[1] as [$valeur, $position]) {
                $numero = substr_count(substr($contenu, 0, $position), "\n") + 1;
                // Un préfixe cité dans un commentaire documente, il n'émet rien.
                $texte = ltrim($lignes[$numero - 1] ?? '');
                if (str_starts_with($texte, '*') || str_starts_with($texte, '//') || str_starts_with($texte, '/*')) {
                    continue;
                }
                $trouves[$valeur][] = $this->relatif($chemin) . ':' . $numero;
            }
        }

        foreach ($trouves as $valeur => $emplacements) {
            $lieu = implode(', ', array_slice($emplacements, 0, 3));

            if (in_array($valeur, Registre::PREFIXES_BANNIS, true)) {
                $this->ajouter('anomalie', 'préfixes', "« {$valeur} » est banni mais encore émis — {$lieu}");
                continue;
            }
            // Un préfixe d'exemple dans un commentaire ne pose pas de problème,
            // mais un préfixe inconnu écrit en dur, si.
            if (! in_array($valeur, $autorises, true)) {
                $this->ajouter('anomalie', 'préfixes', "« {$valeur} » n'est pas déclaré au registre — {$lieu}");
            }
        }

        if (! $this->aDesConstats('préfixes')) {
            $this->ajouter('ok', 'préfixes', count($trouves) . ' préfixe(s) en usage, tous déclarés.');
        }
    }

    /**
     * Un drapeau de fonctionnalité doit porter la même valeur sur les trois
     * canaux. Les dépôts mobile et web ne sont pas toujours là — en intégration
     * continue par exemple — auquel cas le contrôle est ignoré, pas échoué.
     */
    private function controlerDrapeaux(): void
    {
        foreach (Registre::DRAPEAUX as $nom => $sources) {
            $valeurs = [];

            foreach ($sources as $canal => $source) {
                if (isset($source['config'])) {
                    $valeurs[$canal] = (bool) config($source['config']);
                    continue;
                }

                $chemin = base_path($source['fichier']);
                if (! is_file($chemin)) {
                    $this->ajouter('ignore', 'drapeaux', "Dépôt {$canal} absent, « {$nom} » non comparé.");
                    continue;
                }
                if (preg_match($source['motif'], file_get_contents($chemin) ?: '', $m)) {
                    $valeurs[$canal] = $m[1] === 'true';
                } else {
                    $this->ajouter('anomalie', 'drapeaux', "« {$nom} » introuvable dans {$source['fichier']}.");
                }
            }

            if (count(array_unique($valeurs, SORT_REGULAR)) > 1) {
                $detail = implode(', ', array_map(
                    fn ($c, $v) => $c . '=' . ($v ? 'true' : 'false'),
                    array_keys($valeurs),
                    $valeurs,
                ));
                $this->ajouter('anomalie', 'drapeaux', "« {$nom} » diverge : {$detail}");
            } elseif ($valeurs !== []) {
                $etat = reset($valeurs) ? 'activé' : 'désactivé';
                $this->ajouter('ok', 'drapeaux', "« {$nom} » {$etat} sur " . count($valeurs) . ' canal/canaux.');
            }
        }
    }

    /** Le planificateur et le registre doivent décrire les mêmes tâches. */
    private function controlerTaches(): void
    {
        $planifiees = [];
        foreach (app(Schedule::class)->events() as $evenement) {
            if (preg_match('/artisan[\'"]? ([a-z0-9:_-]+)/i', $evenement->command ?? '', $m)) {
                $planifiees[$m[1]] = $evenement->expression;
            }
        }

        foreach (array_keys(Registre::TACHES) as $commande) {
            if (! isset($planifiees[$commande])) {
                $this->ajouter('anomalie', 'tâches', "« {$commande} » est attendue mais n'est plus planifiée.");
            }
        }
        foreach (array_keys($planifiees) as $commande) {
            if (! isset(Registre::TACHES[$commande])) {
                $this->ajouter('anomalie', 'tâches', "« {$commande} » est planifiée mais absente du registre.");
            }
        }

        if (! $this->aDesConstats('tâches')) {
            $this->ajouter('ok', 'tâches', count($planifiees) . ' tâches planifiées, toutes déclarées.');
        }
    }

    /** Réglages sans lesquels une fonction tombe en silence. */
    private function controlerReglages(): void
    {
        // Un poste de développement n'a pas à porter les clés de production :
        // le manque y est signalé, il n'y fait pas échouer l'audit.
        $niveauManque = app()->environment(['production', 'staging']) ? 'anomalie' : 'avertissement';

        foreach (Registre::REGLAGES_CRITIQUES as $cle => $raison) {
            if (blank(config($cle))) {
                $this->ajouter($niveauManque, 'réglages', "{$cle} est vide. {$raison}");
            }
        }

        foreach (Registre::REGLAGES_SUSPECTS as $cle => $regle) {
            if (config($cle) === $regle['interdit']) {
                // En production c'est une anomalie ; ailleurs, un simple rappel.
                $niveau = app()->environment('production') ? 'anomalie' : 'avertissement';
                $this->ajouter($niveau, 'réglages', "{$cle} vaut « {$regle['interdit']} ». {$regle['raison']}");
            }
        }

        if (! $this->aDesConstats('réglages')) {
            $this->ajouter('ok', 'réglages', 'Réglages critiques renseignés, aucun défaut dangereux.');
        }
    }

    /**
     * Les versions ne doivent pas se contredire entre les dépôts. Le contrôle
     * reste souple : il signale, il ne dicte pas de numérotation.
     */
    private function controlerVersions(): void
    {
        $versions = [];

        $pubspec = base_path('../mobile/pubspec.yaml');
        if (is_file($pubspec) && preg_match('/^version:\s*([0-9.]+)\+(\d+)/m', file_get_contents($pubspec) ?: '', $m)) {
            $versions['mobile'] = $m[1] . ' (build ' . $m[2] . ')';
        }

        $package = base_path('../admin/package.json');
        if (is_file($package)) {
            $json = json_decode(file_get_contents($package) ?: '{}', true);
            $versions['dashboard'] = $json['version'] ?? 'non renseignée';
        }

        if ($versions === []) {
            $this->ajouter('ignore', 'versions', 'Dépôts mobile et dashboard absents.');
            return;
        }

        $detail = implode(' · ', array_map(fn ($c, $v) => "{$c} {$v}", array_keys($versions), $versions));
        $this->ajouter('ok', 'versions', $detail);
    }

    // ── Contrôles en base ───────────────────────────────────────────────────

    /** Les colonnes attendues existent-elles vraiment dans la base connectée ? */
    private function controlerSchema(): void
    {
        foreach (Registre::SCHEMA_ATTENDU as $suffixe => $colonnes) {
            $table = project_table($suffixe);

            if (! Schema::hasTable($table)) {
                $this->ajouter('anomalie', 'schéma', "Table {$table} absente.");
                continue;
            }
            $manquantes = array_values(array_filter(
                $colonnes,
                fn ($colonne) => ! Schema::hasColumn($table, $colonne),
            ));
            if ($manquantes !== []) {
                $this->ajouter('anomalie', 'schéma',
                    "{$table} : colonne(s) manquante(s) — " . implode(', ', $manquantes) . '. Un script SQL n\'a pas été joué.');
            }
        }

        if (! $this->aDesConstats('schéma')) {
            $this->ajouter('ok', 'schéma', count(Registre::SCHEMA_ATTENDU) . ' tables conformes au registre.');
        }
    }

    /**
     * Dérive observée dans les données : quels préfixes ont RÉELLEMENT été
     * émis ces derniers jours.
     *
     * C'est le filet de sécurité du contrôle statique : il attrape un préfixe
     * construit dynamiquement, ou un chemin de code oublié lors d'un
     * renommage, sans lire une ligne de source.
     */
    private function controlerDeriveDonnees(int $jours = 30): void
    {
        // Les préfixes connus sont testés du plus long au plus court : sans
        // cela « TONJIPAYIN » avalerait « TONJIPAYOUT ».
        $connus = array_merge(Registre::prefixesAutorises(), Registre::PREFIXES_BANNIS);
        usort($connus, fn ($a, $b) => strlen($b) <=> strlen($a));
        $depuis = now()->subDays($jours);

        foreach (['payin', 'payout'] as $suffixe) {
            $table = project_table($suffixe);
            if (! Schema::hasTable($table)) {
                continue;
            }

            $vus = [];
            $exemples = [];
            foreach (DB::table($table)->where('date_creation', '>=', $depuis)->pluck('trans_id') as $transId) {
                $transId = (string) $transId;
                $prefixe = null;
                foreach ($connus as $candidat) {
                    if (str_starts_with($transId, $candidat)) {
                        $prefixe = $candidat;
                        break;
                    }
                }
                $cle = $prefixe ?? 'inconnu';
                $vus[$cle] = ($vus[$cle] ?? 0) + 1;
                $exemples[$cle] ??= $transId;
            }

            if ($vus === []) {
                $this->ajouter('ok', 'données', "{$table} : aucune écriture depuis {$jours} jours.");
                continue;
            }

            foreach ($vus as $prefixe => $nombre) {
                if (in_array($prefixe, Registre::PREFIXES_BANNIS, true)) {
                    $this->ajouter('anomalie', 'données',
                        "{$table} : {$nombre} ligne(s) émises avec le préfixe banni « {$prefixe} » depuis {$jours} jours.");
                } elseif ($prefixe === 'inconnu') {
                    $this->ajouter('avertissement', 'données',
                        "{$table} : {$nombre} ligne(s) au préfixe non déclaré, par exemple « {$exemples['inconnu']} ». À déclarer au registre ou à corriger.");
                }
            }

            $resume = implode(', ', array_map(fn ($p, $n) => "{$p} ({$n})", array_keys($vus), $vus));
            $this->ajouter('ok', 'données', "{$table} : {$resume}");
        }
    }

    // ── Restitution ─────────────────────────────────────────────────────────

    private function ajouter(string $niveau, string $controle, string $message): void
    {
        $this->constats[] = compact('niveau', 'controle', 'message');
    }

    /** Vrai si un contrôle a déjà produit une anomalie ou un avertissement. */
    private function aDesConstats(string $controle): bool
    {
        foreach ($this->constats as $c) {
            if ($c['controle'] === $controle && in_array($c['niveau'], ['anomalie', 'avertissement'], true)) {
                return true;
            }
        }

        return false;
    }

    private function restituer(): int
    {
        $symboles = ['ok' => '<fg=green>OK  </>', 'avertissement' => '<fg=yellow>!   </>',
                     'anomalie' => '<fg=red>ÉCHEC</>', 'ignore' => '<fg=gray>—   </>'];

        foreach ($this->constats as $c) {
            $this->line($symboles[$c['niveau']] . '  ' . str_pad($c['controle'], 12) . $c['message']);
        }

        $anomalies = count(array_filter($this->constats, fn ($c) => $c['niveau'] === 'anomalie'));
        $avertissements = count(array_filter($this->constats, fn ($c) => $c['niveau'] === 'avertissement'));

        $this->newLine();
        if ($anomalies > 0) {
            $this->error("{$anomalies} anomalie(s), {$avertissements} avertissement(s).");

            return self::FAILURE;
        }
        if ($avertissements > 0 && $this->option('strict')) {
            $this->warn("{$avertissements} avertissement(s), refusés en mode strict.");

            return self::FAILURE;
        }
        $this->info("Aucune anomalie. {$avertissements} avertissement(s).");

        return self::SUCCESS;
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /** @return list<string> Fichiers PHP à scanner, exclusions appliquées. */
    private function fichiersPhp(): array
    {
        $fichiers = [];
        foreach (Registre::DOSSIERS_SCANNES as $dossier) {
            $racine = base_path($dossier);
            if (! is_dir($racine)) {
                continue;
            }
            $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
            foreach ($iterateur as $fichier) {
                if ($fichier->isFile() && $fichier->getExtension() === 'php'
                    && ! in_array($this->relatif($fichier->getPathname()), Registre::FICHIERS_EXCLUS, true)) {
                    $fichiers[] = $fichier->getPathname();
                }
            }
        }

        return $fichiers;
    }

    private function relatif(string $chemin): string
    {
        return ltrim(str_replace(base_path(), '', $chemin), '/');
    }
}
