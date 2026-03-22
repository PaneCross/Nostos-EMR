<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('shared_tenants')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->timestamp('last_activity_at')->nullable();
            $table->integer('last_activity');

            $table->index('user_id');
            $table->index('last_activity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
