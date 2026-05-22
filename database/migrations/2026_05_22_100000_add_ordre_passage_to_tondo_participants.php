<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_participants', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordre_passage')->default(0)->after('statut_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_participants', function (Blueprint $table) {
            $table->dropColumn('ordre_passage');
        });
    }
};
