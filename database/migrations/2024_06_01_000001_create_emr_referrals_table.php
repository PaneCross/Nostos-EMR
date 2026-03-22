<?php

// ─── Migration: emr_referrals ──────────────────────────────────────────────────
// Tracks prospective PACE participants from initial referral through enrollment.
//
// CMS PACE enrollment workflow:
//   new → intake_scheduled → intake_in_progress → intake_complete
//     → eligibility_pending → pending_enrollment → enrolled
//   OR: any state → declined / withdrawn
//
// When status reaches 'enrolled', EnrollmentService:
//   1. Creates or links an emr_participants record
//   2. Sets participant.enrollment_status = 'enrolled', enrollment_date = today
//   3. Logs action='participant_enrolled' in audit_log
//
// The participant_id FK is nullable — populated only when the referral
// reaches intake_complete and a participant record is created.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_referrals', function (Blueprint $table) {
            // ── Identity ──────────────────────────────────────────────────────
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->constrained('shared_sites')
                ->cascadeOnDelete();

            // ── Referral source details ───────────────────────────────────────
            $table->string('referred_by_name', 150);      // Person who made the referral
            $table->string('referred_by_org', 150)->nullable(); // Org (hospital, clinic, etc.)
            $table->date('referral_date');

            // ── Referral source type (for reporting + pipeline filters) ────────
            // Determines which intake workflows apply (e.g. hospital = fast-track)
            $table->string('referral_source', 30);        // enum enforced by CHECK below

            // ── Prospective participant ────────────────────────────────────────
            // NULL until intake begins. Populated when 'Create Participant Record'
            // is clicked at intake_complete stage.
            $table->foreignId('participant_id')
                ->nullable()
                ->constrained('emr_participants')
                ->nullOnDelete();

            // ── Assignment ────────────────────────────────────────────────────
            // Enrollment team member responsible for managing this referral.
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            // ── Workflow status ────────────────────────────────────────────────
            // State machine enforced by EnrollmentService::transition().
            // Full valid path: new → intake_scheduled → intake_in_progress →
            //   intake_complete → eligibility_pending → pending_enrollment → enrolled
            // Terminal exit: any state → declined / withdrawn
            $table->string('status', 40)->default('new');  // CHECK constraint below

            // ── Terminal state reasons ────────────────────────────────────────
            $table->string('decline_reason', 300)->nullable();
            $table->string('withdrawn_reason', 300)->nullable();

            // ── Free text ────────────────────────────────────────────────────
            $table->text('notes')->nullable();

            // ── Audit ────────────────────────────────────────────────────────
            $table->foreignId('created_by_user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();

            $table->timestamps();
        });

        // ── PostgreSQL CHECK constraints ──────────────────────────────────────
        DB::statement("ALTER TABLE emr_referrals ADD CONSTRAINT referrals_source_check
            CHECK (referral_source IN ('hospital','physician','family','community','self','other'))");

        DB::statement("ALTER TABLE emr_referrals ADD CONSTRAINT referrals_status_check
            CHECK (status IN ('new','intake_scheduled','intake_in_progress','intake_complete',
                'eligibility_pending','pending_enrollment','enrolled','declined','withdrawn'))");

        // ── Indexes ───────────────────────────────────────────────────────────
        // Pipeline view: all referrals for a tenant grouped by status
        Schema::table('emr_referrals', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'referrals_tenant_status_idx');
            $table->index(['assigned_to_user_id', 'status'], 'referrals_assignee_status_idx');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_referrals DROP CONSTRAINT IF EXISTS referrals_source_check');
        DB::statement('ALTER TABLE emr_referrals DROP CONSTRAINT IF EXISTS referrals_status_check');
        Schema::dropIfExists('emr_referrals');
    }
};
