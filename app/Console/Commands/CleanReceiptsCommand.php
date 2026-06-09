<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanReceiptsCommand extends Command
{
    protected $signature   = 'tondo:clean-receipts {--dry-run : Liste les fichiers sans les supprimer}';
    protected $description = 'Supprime les fichiers PDF de receipts/ vieux de plus de 24h';

    public function handle(): int
    {
        $dir     = public_path('receipts');
        $cutoff  = now()->subHours(24)->timestamp;
        $dryRun  = $this->option('dry-run');
        $deleted = 0;

        if (! is_dir($dir)) {
            $this->info('Dossier receipts/ inexistant — rien à faire.');
            return 0;
        }

        foreach (glob($dir . '/*.pdf') as $file) {
            if (filemtime($file) < $cutoff) {
                if ($dryRun) {
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
