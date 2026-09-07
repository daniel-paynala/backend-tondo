<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commentaire libre et optionnel laissé par le cotisant au moment de payer.
 *
 * Porté par `payin` ET par `paiements` : le paiement Airtel est confirmé en
 * asynchrone, la ligne `paiements` est créée plus tard à partir du `payin`
 * (polling /status, réconciliation, bot). Sans la colonne sur `payin`, le
 * commentaire serait perdu entre l'initiation et la confirmation.
 *
 * Équivalent PROD : database/supabase/022_tonji_commentaire_cotisation.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_payin', function (Blueprint $table) {
            // Saisi par le cotisant à l'initiation, recopié sur le paiement.
            $table->text('commentaire')->nullable();
        });

        Schema::table('tondo_paiements', function (Blueprint $table) {
            // Champ descriptif : jamais utilisé dans un calcul ni une règle métier.
            $table->text('commentaire')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tondo_payin', function (Blueprint $table) {
            $table->dropColumn('commentaire');
        });

        Schema::table('tondo_paiements', function (Blueprint $table) {
            $table->dropColumn('commentaire');
        });
    }
};
