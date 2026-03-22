<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();
            $table->string('code_hash');         // bcrypt hash of the 6-digit code
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_otp_codes');
    }
};
