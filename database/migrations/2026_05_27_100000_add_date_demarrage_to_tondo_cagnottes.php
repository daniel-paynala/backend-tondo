<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->timestampTz('date_demarrage')->nullable()->after('date_fin');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn('date_demarrage');
        });
    }
};
