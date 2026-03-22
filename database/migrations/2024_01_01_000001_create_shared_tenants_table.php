<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('transport_mode', ['direct', 'broker'])->default('direct');
            $table->string('cms_contract_id')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->unsignedSmallInteger('auto_logout_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_tenants');
    }
};
