<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('prefixes');
        });
    }

    public function down(): void
    {
        Schema::table('tondo_project_config', function (Blueprint $table) {
            $table->dropColumn('actif');
        });
    }
};
