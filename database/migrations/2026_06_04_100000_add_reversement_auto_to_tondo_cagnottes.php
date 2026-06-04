<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            // Cotisation ouverte uniquement — ignoré pour les tontines.
            $table->boolean('reversement_auto')->default(false)->after('date_fin');
            // Fréquence en mois (mode libre). null = déclenché par l'échéance.
            $table->unsignedTinyInteger('reversement_auto_frequence_mois')->nullable()->after('reversement_auto');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn(['reversement_auto', 'reversement_auto_frequence_mois']);
        });
    }
};
