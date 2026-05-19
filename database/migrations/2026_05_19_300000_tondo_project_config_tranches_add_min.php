<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute `montant_min` dans chaque tranche du JSON `tranches`.
 *
 * Les tranches existantes sont mises à jour en dérivant montant_min
 * depuis l'ordre des tranches (tranche 0 : 100, tranche N : max_N-1 + 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tondo_project_config')->orderBy('id')->each(function ($row) {
            $tranches = json_decode($row->tranches, true);
            if (! is_array($tranches) || empty($tranches)) {
                return;
            }

            $prevMax = 99;
            foreach ($tranches as &$t) {
                if (! isset($t['montant_min'])) {
                    $t['montant_min'] = $prevMax + 1;
                }
                $prevMax = $t['montant_max'] ?? PHP_INT_MAX;
            }
            unset($t);

            DB::table('tondo_project_config')
                ->where('id', $row->id)
                ->update(['tranches' => json_encode($tranches)]);
        });
    }

    public function down(): void
    {
        DB::table('tondo_project_config')->orderBy('id')->each(function ($row) {
            $tranches = json_decode($row->tranches, true);
            if (! is_array($tranches)) {
                return;
            }
            foreach ($tranches as &$t) {
                unset($t['montant_min']);
            }
            unset($t);
            DB::table('tondo_project_config')
                ->where('id', $row->id)
                ->update(['tranches' => json_encode($tranches)]);
        });
    }
};
