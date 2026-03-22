<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')
                  ->constrained('emr_chat_channels')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('shared_users')
                  ->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('last_read_at')->nullable();

            $table->unique(['channel_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_memberships');
    }
};
