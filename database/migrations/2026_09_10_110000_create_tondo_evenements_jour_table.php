<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégats quotidiens de la télémétrie produit.
 *
 * Le dashboard lit cette table, jamais les lignes brutes : à la cible de
 * fréquentation, les compter à chaque affichage rendrait la page inutilisable.
 * Les brutes sont purgées à 90 jours, ces agrégats sont conservés.
 *
 * Équivalent PROD : database/supabase/027_tonji_evenements_jour.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tondo_evenements_jour', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->date('jour');
            $table->string('nom');
            $table->string('canal')->default('app');
            // '' plutôt que NULL : deux NULL ne sont pas égaux en Postgres et
            // l'index unique ne dédoublonnerait pas les lignes sans plateforme.
            $table->string('plateforme')->default('');
            $table->integer('compte')->default(0);
            $table->integer('utilisateurs_uniques')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'jour', 'nom', 'canal', 'plateforme'], 'evenements_jour_cle');
            $table->index(['project_id', 'nom', 'jour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tondo_evenements_jour');
    }
};
