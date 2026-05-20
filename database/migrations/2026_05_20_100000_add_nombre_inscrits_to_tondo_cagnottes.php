<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute `nombre_inscrits` (participants effectivement inscrits) sur
 * tondo_cagnottes. Distinct de `nombre_participants` qui reste le
 * nombre DÉCLARÉ à la création (cible de la tontine).
 *
 * Backfill : on compte les lignes tondo_participants existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->unsignedBigInteger('nombre_inscrits')->default(0)->after('nombre_participants');
        });

        // Backfill depuis tondo_participants
        DB::statement(<<<'SQL'
            UPDATE tondo_cagnottes c
            SET nombre_inscrits = (
                SELECT COUNT(*) FROM tondo_participants p
                WHERE p.cagnotte_id = c.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn('nombre_inscrits');
        });
    }
};
