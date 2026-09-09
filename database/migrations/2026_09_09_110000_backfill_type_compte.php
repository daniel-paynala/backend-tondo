<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renseigne `type_compte` pour les comptes antérieurs à la bascule associations.
 *
 * Uniquement ceux dont `type_client = 'particulier'` : ce type dérive du grade
 * Airtel (SUBS/TEMP), donc le compte est certainement un particulier. Les autres
 * restent NULL et passeront par l'écran de choix, qui confronte désormais le
 * choix au KYC — un compte 'entreprise' peut être un MERCHVIP ou un HMERA,
 * c'est-à-dire une association.
 *
 * Équivalent PROD : database/supabase/025_backfill_type_compte.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('type_compte')
            ->where('type_client', 'particulier')
            ->update(['type_compte' => 'particulier', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Irréversible sans trace de l'état antérieur : on ne remet pas des
        // comptes à NULL, ce qui les renverrait sur l'écran de choix.
    }
};
