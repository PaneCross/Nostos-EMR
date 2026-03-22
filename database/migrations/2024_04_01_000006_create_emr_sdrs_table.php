<?php

// ─── Migration: emr_sdrs (Service Delivery Requests) ─────────────────────────
// Cross-department requests for services, referrals, orders, and care changes.
// PACE operational requirement: all SDRs must be completed within 72 hours.
//
// 72-Hour Enforcement:
//   - due_at = submitted_at + 72h (enforced in Sdr model boot())
//   - SdrDeadlineEnforcementJob runs every 15 min via Laravel Scheduler
//   - Warning alert at 24h remaining (info → assigned dept)
//   - Urgent alert at 8h remaining  (warning → assigned dept)
//   - Escalation when overdue       (critical → assigned dept + qa_compliance)
//
// Escalation fields:
//   escalated = true when SdrDeadlineEnforcementJob fires past due_at
//   escalation_reason set automatically; escalated_at = time of escalation
//
// All deletes are soft deletes (deleted_at).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_sdrs', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Requesting department ──────────────────────────────────────────
            $table->foreignId('requesting_user_id')
                ->constrained('shared_users')
                ->restrictOnDelete();
            $table->string('requesting_department', 50);   // dept slug

            // ── Assignment ────────────────────────────────────────────────────
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->string('assigned_department', 50);    // dept slug

            // ── Request details ───────────────────────────────────────────────
            $table->enum('request_type', [
                'lab_order',
                'referral',
                'home_care_visit',
                'transport_request',
                'equipment_dme',
                'pharmacy_change',
                'assessment_request',
                'care_plan_update',
                'other',
            ]);
            $table->text('description');
            $table->enum('priority', ['routine', 'urgent', 'emergent'])->default('routine');

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', [
                'submitted',
                'acknowledged',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('submitted');

            // ── 72-hour deadline ──────────────────────────────────────────────
            $table->timestamp('submitted_at');
            $table->timestamp('due_at');   // Always = submitted_at + 72h (model enforces this)

            // ── Completion ────────────────────────────────────────────────────
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            // ── Escalation ────────────────────────────────────────────────────
            $table->boolean('escalated')->default(false);
            $table->text('escalation_reason')->nullable();
            $table->timestamp('escalated_at')->nullable();

            // ── Soft delete ───────────────────────────────────────────────────
            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['tenant_id', 'status', 'due_at'],           'sdrs_tenant_status_due_idx');
            $table->index(['participant_id', 'status'],                 'sdrs_participant_status_idx');
            $table->index(['assigned_department', 'status', 'due_at'], 'sdrs_dept_status_due_idx');
            $table->index(['escalated', 'escalated_at'],               'sdrs_escalated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_sdrs');
    }
};
