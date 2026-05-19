<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace les 3 colonnes de retrait fixes par un JSON `tranches` libre.
 *
 * Avant : retrait_seuil_tranche / retrait_taux_pourcentage / retrait_forfait
 * Après : tranches JSON [{montant_max, type, valeur}]
 *
 * Les lignes existantes sont migrées : les 3 colonnes → 2 tranches par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->json('tranches')->default('[]')->after('plafond_journalier');
        });

        // Migre les lignes existantes vers le nouveau format
        DB::table('tondo_project_config')->orderBy('id')->each(function ($row) {
            $tranches = [
                [
                    'montant_max' => $row->retrait_seuil_tranche,
                    'type'        => 'pourcentage',
                    'valeur'      => (float) $row->retrait_taux_pourcentage,
                ],
                [
                    'montant_max' => null,
                    'type'        => 'forfait',
                    'valeur'      => (int) $row->retrait_forfait,
                ],
            ];
            DB::table('tondo_project_config')
                ->where('id', $row->id)
                ->update(['tranches' => json_encode($tranches)]);
        });

        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->dropColumn(['retrait_seuil_tranche', 'retrait_taux_pourcentage', 'retrait_forfait']);
        });
    }

    public function down(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->integer('retrait_seuil_tranche')->default(166667);
            $table->float('retrait_taux_pourcentage')->default(0.03);
            $table->integer('retrait_forfait')->default(5000);
        });

        // Restaure les colonnes à partir de la première tranche
        DB::table('tondo_project_config')->orderBy('id')->each(function ($row) {
            $tranches = json_decode($row->tranches, true) ?? [];
            $t1 = collect($tranches)->first(fn ($t) => $t['type'] === 'pourcentage');
            $t2 = collect($tranches)->first(fn ($t) => $t['type'] === 'forfait');
            DB::table('tondo_project_config')
                ->where('id', $row->id)
                ->update([
                    'retrait_seuil_tranche'    => $t1['montant_max'] ?? 166667,
                    'retrait_taux_pourcentage' => $t1['valeur'] ?? 0.03,
                    'retrait_forfait'          => $t2['valeur'] ?? 5000,
                ]);
        });

        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->dropColumn('tranches');
        });
    }
};
