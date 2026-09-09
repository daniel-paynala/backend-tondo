<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les associations sont approuvées d'emblée : plus de dossier documentaire.
 *
 * Le KYC Airtel fait foi sur l'identité de l'association. Seule la demande de
 * dépassement du plafond de 10 M reste instruite dans le dashboard.
 *
 * Équivalent PROD : database/supabase/024_associations_sans_dossier.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tondo_organisations ALTER COLUMN statut SET DEFAULT 'approuve'");

        // Personne n'instruira plus les dossiers en attente : leurs titulaires
        // resteraient bloqués hors de l'app. 'rejete' et 'suspendu' sont des
        // décisions de modération et ne sont pas touchés.
        DB::table('tondo_organisations')
            ->where('statut', 'en_attente')
            ->update(['statut' => 'approuve', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tondo_organisations ALTER COLUMN statut SET DEFAULT 'en_attente'");
    }
};
