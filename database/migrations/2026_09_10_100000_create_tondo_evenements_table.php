<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Télémétrie produit : ce que les utilisateurs font dans l'app.
 *
 * Distincte de `tondo_logs`, qui journalise les actions ADMIN à des fins
 * d'audit. Aucun contenu saisi n'entre ici : ni numéro, ni montant exact
 * (des tranches uniquement), ni commentaire de cotisation.
 *
 * Équivalent PROD : database/supabase/026_tonji_evenements.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tondo_evenements', function (Blueprint $table) {
            // Généré par le client : sert de clé d'idempotence, un lot renvoyé
            // après un timeout ne doit pas doubler les compteurs.
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            // Nullable : un événement peut précéder la création du compte.
            $table->uuid('user_id')->nullable();
            // Généré au lancement de l'app : reconstitue un parcours.
            $table->string('session_id');
            $table->string('nom');
            $table->string('canal')->default('app');
            $table->string('plateforme')->nullable();
            $table->string('version_app')->nullable();
            $table->json('contexte')->default('{}');
            // Horodatage client (l'app bufferise et peut être hors ligne).
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'nom', 'occurred_at']);
            $table->index(['session_id', 'occurred_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tondo_evenements');
    }
};
