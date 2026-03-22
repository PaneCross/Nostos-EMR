<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')
                  ->constrained('emr_chat_channels')
                  ->cascadeOnDelete();
            $table->foreignId('sender_user_id')
                  ->constrained('shared_users')
                  ->cascadeOnDelete();
            $table->text('message_text');
            $table->enum('priority', ['standard', 'urgent'])->default('standard');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('edited_at')->nullable();

            // HIPAA 6-year retention: soft deletes only, NEVER hard delete.
            // Deleted messages render as "This message was deleted" in the UI.
            $table->softDeletes();

            $table->index(['channel_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_messages');
    }
};
