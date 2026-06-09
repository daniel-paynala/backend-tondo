<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute numero_retrait_masque à tondo_cagnottes.
 * Cette colonne était référencée par le controller mais n'avait jamais été
 * créée en base (la migration _add_numero_retrait_ supposait qu'elle existait
 * déjà via ->after(), ignoré sur PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->string('numero_retrait_masque', 25)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn('numero_retrait_masque');
        });
    }
};
