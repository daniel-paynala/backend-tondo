<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tondo_paiements_en_attente', function (Blueprint $table) {
            $table->string('trans_id')->primary();
            $table->string('numero_wa', 30);
            $table->string('project_id');
            $table->string('cagnotte_ref', 10);
            $table->unsignedInteger('montant');
            $table->string('prenom', 100);
            $table->string('user_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tondo_paiements_en_attente');
    }
};
