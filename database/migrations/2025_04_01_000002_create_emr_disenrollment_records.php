<?php

// ─── Migration 90 ──────────────────────────────────────────────────────────────
// Creates the emr_disenrollment_records table per 42 CFR §460.116.
//
// When a participant disenrolls (voluntarily or involuntarily), PACE must:
// 1. Create a written transition plan within 30 days.
// 2. Notify CMS/SMA (State Medicaid Agency) per their state's requirement.
// 3. Notify the participant's new providers.
//
// This table tracks the documentation and notification workflow for each
// disenrollment event. One record per participant disenrollment.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_disenrollment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();

            // Disenrollment metadata (mirrors emr_referrals disenrollment fields)
            $table->string('reason', 50);            // voluntary/involuntary/deceased/moved/nf_admission/other
            $table->date('effective_date');           // date enrollment ended
            $table->text('notes')->nullable();        // freeform notes from enrollment coordinator

            // 42 CFR §460.116: Transition plan documentation
            $table->string('transition_plan_status', 30)->default('pending');
            $table->text('transition_plan_text')->nullable();
            $table->date('transition_plan_due_date')->nullable(); // effective_date + 30 days
            $table->date('transition_plan_completed_date')->nullable();
            $table->foreignId('transition_plan_completed_by_user_id')->nullable()
                ->constrained('shared_users')->nullOnDelete();

            // CMS/SMA notification tracking
            $table->boolean('cms_notification_required')->default(false);
            $table->timestamp('cms_notified_at')->nullable();
            $table->foreignId('cms_notified_by_user_id')->nullable()
                ->constrained('shared_users')->nullOnDelete();
            $table->text('cms_notification_notes')->nullable();

            // Provider notification tracking
            $table->boolean('providers_notified')->default(false);
            $table->timestamp('providers_notified_at')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });

        // Valid disenrollment reasons — must match EnrollmentService::disenroll() validation
        DB::statement("
            ALTER TABLE emr_disenrollment_records
            ADD CONSTRAINT emr_disenrollment_records_reason_check
            CHECK (reason IN ('voluntary', 'involuntary', 'deceased', 'moved', 'nf_admission', 'other'))
        ");

        // Transition plan status lifecycle
        DB::statement("
            ALTER TABLE emr_disenrollment_records
            ADD CONSTRAINT emr_disenrollment_records_transition_plan_status_check
            CHECK (transition_plan_status IN ('pending', 'in_progress', 'completed', 'not_required'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_disenrollment_records');
    }
};
