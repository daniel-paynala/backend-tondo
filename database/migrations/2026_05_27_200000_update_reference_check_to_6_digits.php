<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Met à jour la contrainte CHECK sur tondo_cagnottes.reference :
 *   avant : ^\d{4,5}$   (4-5 chiffres)
 *   après : ^\d{6}$     (6 chiffres exactement)
 *
 * La contrainte UNIQUE reste inchangée — elle garantit déjà
 * qu'aucune référence ne peut être en double côté DB.
 *
 * NOT VALID : les lignes existantes (4-5 chiffres) ne sont pas
 * re-validées — uniquement les nouvelles insertions sont concernées.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Supprimer l'ancienne contrainte (nom auto-généré par Postgres).
        DB::statement('ALTER TABLE tondo_cagnottes DROP CONSTRAINT IF EXISTS tondo_cagnottes_reference_check');

        // Nouvelle contrainte : 6 chiffres exacts.
        // NOT VALID → les lignes existantes (4-5 chiffres) ne sont pas rejetées.
        DB::statement("
            ALTER TABLE tondo_cagnottes
            ADD CONSTRAINT tondo_cagnottes_reference_check
            CHECK (reference ~ '^\d{6}$') NOT VALID
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tondo_cagnottes DROP CONSTRAINT IF EXISTS tondo_cagnottes_reference_check');

        DB::statement("
            ALTER TABLE tondo_cagnottes
            ADD CONSTRAINT tondo_cagnottes_reference_check
            CHECK (reference ~ '^\d{4,5}$') NOT VALID
        ");
    }
};
