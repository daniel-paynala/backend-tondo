<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trace les statuts de livraison des messages WhatsApp sortants.
 * Alimenté par le status callback Twilio (POST /api/whatsapp/status).
 *
 * Statuts possibles : sent | delivered | read | failed | undelivered
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tondo_whatsapp_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('message_sid')->unique();       // identifiant Twilio du message
            $table->string('statut', 30)->nullable();      // sent | delivered | read | failed | undelivered
            $table->string('numero_dest', 30)->nullable(); // numéro destinataire
            $table->string('numero_src', 30)->nullable();  // numéro source Tondo WhatsApp
            $table->string('error_code', 10)->nullable();  // code erreur Twilio si failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tondo_whatsapp_logs');
    }
};
