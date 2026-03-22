<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_sites', function (Blueprint $table) {
            $table->string('mrn_prefix', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('shared_sites', function (Blueprint $table) {
            $table->dropColumn('mrn_prefix');
        });
    }
};
