<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_ehi_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('token', 64)->unique();   // secure random hex token
            $table->string('file_path', 500)->nullable();
            $table->string('status', 20)->default('pending');  // pending, ready, expired
            $table->timestamp('expires_at');                   // +24h from creation
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('requested_by_user_id')->references('id')->on('shared_users');

            $table->index(['participant_id', 'tenant_id']);
            $table->index(['token', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_ehi_exports');
    }
};
