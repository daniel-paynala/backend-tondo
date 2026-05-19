<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            // Indicatif téléphonique du pays (ex : "241" pour le Gabon).
            // Stocké sans "+" pour simplifier les comparaisons de préfixes.
            $table->string('indicatif', 10)->nullable()->after('pays');

            // Préfixes locaux identifiant l'opérateur (ex : ["077","076","074"]).
            // Format attendu : chiffres locaux avec zéro initial (ex : "077").
            $table->json('prefixes')->nullable()->after('indicatif');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->dropColumn(['indicatif', 'prefixes']);
        });
    }
};
