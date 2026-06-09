<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * ALTER TYPE ... ADD VALUE ne peut pas s'exécuter dans une transaction
     * sur PostgreSQL. On désactive le wrapping transactionnel.
     */
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE tondo_statut_cagnotte ADD VALUE IF NOT EXISTS 'annulee'");
    }

    public function down(): void
    {
        // PostgreSQL ne permet pas de supprimer une valeur d'un enum existant.
        // Pour rollback : recréer le type sans 'annulee' (opération lourde, non implémentée).
    }
};
