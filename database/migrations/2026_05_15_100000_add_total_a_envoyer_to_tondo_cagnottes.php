<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute `total_a_envoyer` à `tondo_cagnottes`.
 *
 * Modèle A (cotisant absorbe les frais Airtel) impose qu'on stocke
 * séparément :
 *  - montant_beneficiaire : cash net que le bénéficiaire reçoit en main
 *  - total_a_envoyer      : somme des envois Mobile Money (= cash + frais Airtel)
 *  - montant_avec_frais   : total payé par les cotisants (= total_a_envoyer + 2 % Paynala)
 *
 * Sans cette colonne on ne peut pas réconcilier en cas de différence entre
 * cash livré et total débité du wallet émetteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->bigInteger('total_a_envoyer')->nullable()->after('montant_avec_frais');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn('total_a_envoyer');
        });
    }
};
