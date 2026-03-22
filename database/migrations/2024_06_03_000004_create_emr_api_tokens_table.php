<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');

            // Token is stored as a SHA-256 hash. The plaintext is shown once at creation.
            // 64-char hex = SHA-256 output.
            $table->char('token', 64)->unique();

            // FHIR scopes: e.g. ["patient.read", "observation.read", "medication.read"]
            $table->jsonb('scopes')->default('[]');
            $table->string('name', 200)->nullable(); // human-readable label

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // null = never expires

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('shared_users')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');

            $table->index(['tenant_id', 'user_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_api_tokens');
    }
};
