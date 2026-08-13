<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les préférences de notification e-mail (jsonb) à la table des admins.
 * Utilise project_table() pour cibler le bon préfixe selon l'environnement
 * (tondo_admins en dev, tonji_admins en prod). En prod (base bootstrappée par
 * SQL), appliquer l'ALTER TABLE équivalent directement si les migrations ne
 * sont pas jouées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(project_table('admins'), function (Blueprint $table) {
            $table->jsonb('notif_prefs')->nullable()->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table(project_table('admins'), function (Blueprint $table) {
            $table->dropColumn('notif_prefs');
        });
    }
};
