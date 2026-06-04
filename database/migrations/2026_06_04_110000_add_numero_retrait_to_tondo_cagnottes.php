<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stocke le numéro de retrait E.164 (non masqué) pour permettre au scheduler
 * de reversement automatique de déclencher le payout sans intervention du gérant.
 * Non exposé dans les réponses API mobiles (uniquement lecture interne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->string('numero_retrait', 20)->nullable()->after('numero_retrait_masque');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn('numero_retrait');
        });
    }
};
