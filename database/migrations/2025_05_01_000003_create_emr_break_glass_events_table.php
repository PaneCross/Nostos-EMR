<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HIPAA Emergency Access Override (Break-the-Glass) event log.
     * Append-only — no UPDATE or DELETE ever. Each row is a permanent audit record.
     *
     * Access expires 4 hours after grant. Supervisors acknowledge reviewed events.
     * All events create critical alerts for it_admin + qa_compliance.
     */
    public function up(): void
    {
        Schema::create('emr_break_glass_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');           // User who invoked emergency access
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');    // Participant whose record was accessed

            $table->text('justification');                  // Required, min 20 chars
            $table->timestamp('access_granted_at');
            $table->timestamp('access_expires_at');         // Always access_granted_at + 4 hours
            $table->string('ip_address', 45)->nullable();   // IPv4 or IPv6

            // Supervisor acknowledgment (non-nullable FK = not yet acknowledged)
            $table->unsignedBigInteger('acknowledged_by_supervisor_user_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            // Append-only — no updated_at column
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('shared_users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $table->foreign('participant_id')->references('id')->on('emr_participants')->cascadeOnDelete();
            $table->foreign('acknowledged_by_supervisor_user_id')->references('id')->on('shared_users')->nullOnDelete();

            $table->index(['user_id', 'participant_id', 'access_expires_at']);
            $table->index(['tenant_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_break_glass_events');
    }
};
