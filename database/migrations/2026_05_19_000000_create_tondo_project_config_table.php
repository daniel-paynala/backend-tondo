<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Config tarifaire par projet — une ligne par project_id.
 * Permet la modification live depuis le dashboard admin sans redéploiement.
 * Fallback : config/airtel.php (variables d'environnement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tondo_project_config', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->unique();

            // Commission Paynala (ex: 0.02 = 2 %)
            $table->decimal('commission_paynala', 6, 4)->default(0.02);

            // Plafonds Airtel
            $table->integer('plafond_par_envoi')->default(500_000);
            $table->integer('plafond_journalier')->default(2_500_000);

            // Grille retrait Airtel
            $table->integer('retrait_seuil_tranche')->default(166_667);
            $table->decimal('retrait_taux_pourcentage', 6, 4)->default(0.03);
            $table->integer('retrait_forfait')->default(5_000);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tondo_project_config');
    }
};
