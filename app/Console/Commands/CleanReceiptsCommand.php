<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Nettoyage des reçus PDF temporaires générés dans public/receipts/.
 *
 * Les reçus PDF sont créés à la volée par ReceiptService et exposés via une
 * URL publique temporaire envoyée à l'utilisateur WhatsApp. Ils n'ont pas
 * vocation à être conservés indéfiniment : cette commande supprime les fichiers
 * vieux de plus de 24 heures.
 *
 * Planification recommandée : quotidienne à 02h00 (heures creuses Gabon).
 *
 * L'option --dry-run permet de lister les fichiers sans les supprimer (audit).
 */
class CleanReceiptsCommand extends Command
{
    protected $signature   = 'tondo:clean-receipts {--dry-run : Liste les fichiers sans les supprimer}';
    protected $description = 'Supprime les fichiers PDF de receipts/ vieux de plus de 24h';

    /**
     * Point d'entrée de la commande.
     *
     * @return int Code de retour (0 = succès).
     */
    public function handle(): int
    {
        $dir     = public_path('receipts');                // Dossier cible dans public/.
        $cutoff  = now()->subHours(24)->timestamp;         // Timestamp de coupure (Unix).
        $dryRun  = $this->option('dry-run');
        $deleted = 0;

        if (! is_dir($dir)) {
            $this->info('Dossier receipts/ inexistant — rien à faire.');
            return 0;
        }

        // Parcourir tous les PDFs du dossier et supprimer les plus vieux que 24h.
        foreach (glob($dir . '/*.pdf') as $file) {
            if (filemtime($file) < $cutoff) {
                if ($dryRun) {
                    // Mode simulation : juste afficher le nom du fichier.
                    $this->line('  [dry-run] ' . basename($file));
                } else {
                    unlink($file);
                }
                $deleted++;
            }
        }

        $label = $dryRun ? 'à supprimer' : 'supprimés';
        $this->info("PDF {$label} : {$deleted}");

        return 0;
    }
}
