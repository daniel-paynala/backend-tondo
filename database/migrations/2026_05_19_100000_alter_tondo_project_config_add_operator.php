<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait évoluer tondo_project_config vers un modèle per-opérateur / per-pays.
 *
 * - Ajoute `operateur` (ex : "airtel", "moov") et `pays` (ex : "GA", "CG").
 * - Supprime `commission_paynala` (marge Paynala, backend-only, reste dans
 *   config/airtel.php — n'a pas à varier par opérateur).
 * - Change la contrainte unique : project_id seul → (project_id, operateur, pays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            // Nouveaux champs opérateur / pays
            $table->string('operateur', 50)->default('airtel')->after('project_id');
            $table->string('pays', 5)->default('GA')->after('operateur');

            // commission_paynala reste dans la table — elle est désormais
            // configurable par opérateur / pays (mettre 0 si non applicable).

            // Unique (project_id, operateur, pays) au lieu de project_id seul
            $table->dropUnique(['project_id']);
            $table->unique(['project_id', 'operateur', 'pays']);
        });
    }

    public function down(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'operateur', 'pays']);
            $table->dropColumn(['operateur', 'pays']);
            $table->unique(['project_id']);
        });
    }
};
