<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();

            // direct = DM between two users
            // department = one per department, all dept users auto-joined
            // participant_idt = per-participant IDT care team channel
            // broadcast = org-wide announcements, all users joined
            $table->enum('channel_type', ['direct', 'department', 'participant_idt', 'broadcast']);

            $table->string('name')->nullable(); // null for direct channels
            $table->foreignId('participant_id')
                  ->nullable()
                  ->constrained('emr_participants')
                  ->nullOnDelete();
            $table->foreignId('created_by_user_id')
                  ->constrained('shared_users')
                  ->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'channel_type']);
            $table->index(['tenant_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_channels');
    }
};
