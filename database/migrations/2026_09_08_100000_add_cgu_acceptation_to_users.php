<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mémorise la version des CGU acceptée par chaque utilisateur.
 *
 * La version est l'empreinte du texte affiché, produit par CguService à partir
 * de la config opérateur. La comparer à la version courante dit si l'utilisateur
 * doit réaccepter. NULL = n'a jamais accepté.
 *
 * Équivalent PROD : database/supabase/023_cgu_acceptation.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('cgu_version')->nullable();
            $table->timestamp('cgu_acceptee_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cgu_version', 'cgu_acceptee_at']);
        });
    }
};
