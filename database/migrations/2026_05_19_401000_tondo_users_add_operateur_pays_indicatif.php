<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('operateur', 50)->nullable()->after('kyc_valide');
            $table->char('pays', 2)->nullable()->after('operateur');
            $table->string('indicatif', 10)->nullable()->after('pays');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['operateur', 'pays', 'indicatif']);
        });
    }
};
