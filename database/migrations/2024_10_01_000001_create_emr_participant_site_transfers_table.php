<?php

// ─── Migration 67: emr_participant_site_transfers ─────────────────────────────
// Tracks all site-to-site participant transfers within a PACE organization.
//
// Workflow:
//   1. Enrollment staff submits a transfer request (status=pending)
//   2. Enrollment Admin / IT Admin approves (status=approved, approved_at set)
//   3. TransferCompletionJob runs daily 7am — once effective_date ≤ today and
//      status=approved, it sets participant.site_id = to_site_id (status=completed)
//   4. Prior site staff retain read-only access for 90 days post-transfer
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participant_site_transfers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('from_site_id');
            $table->unsignedBigInteger('to_site_id');

            $table->string('transfer_reason', 30);   // participant_request|relocation|capacity|program_closure|other
            $table->text('transfer_reason_notes')->nullable();

            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->date('effective_date');
            $table->string('status', 20)->default('pending');   // pending|approved|completed|cancelled
            $table->boolean('notification_sent')->default(false);

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────
            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('from_site_id')->references('id')->on('shared_sites');
            $table->foreign('to_site_id')->references('id')->on('shared_sites');
            $table->foreign('requested_by_user_id')->references('id')->on('shared_users');
            $table->foreign('approved_by_user_id')->references('id')->on('shared_users');

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['effective_date', 'status']);
        });

        // ── Check constraints (PostgreSQL — DB::statement pattern) ────────────
        DB::statement("ALTER TABLE emr_participant_site_transfers ADD CONSTRAINT emr_site_transfers_reason_check CHECK (transfer_reason IN ('participant_request','relocation','capacity','program_closure','other'))");
        DB::statement("ALTER TABLE emr_participant_site_transfers ADD CONSTRAINT emr_site_transfers_status_check CHECK (status IN ('pending','approved','completed','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participant_site_transfers');
    }
};
