<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Migration: emr_state_medicaid_configs ────────────────────────────────────
// Stores per-tenant state Medicaid encounter submission configuration.
// PACE participants are dually eligible; many states require separate 837
// encounter submissions to the state Medicaid agency with state-specific
// companion guides. Rules vary dramatically by state (timing, format, fields).
//
// One config per tenant per state_code (unique constraint enforced).
// Only IT Admin can manage these configurations.
//
// submission_format enum:
//   '837P'  — Professional claim format
//   '837I'  — Institutional/facility claim format
//   'custom' — State-specific non-X12 format
//
// Phase 9C — Part B (State Medicaid Configuration Framework)
// DEBT-038: State Medicaid encounter submission support
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_state_medicaid_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // State identification
            $table->char('state_code', 2);                          // 2-letter state code (e.g. 'CA', 'TX')
            $table->string('state_name', 100);                      // Full state name (e.g. 'California')

            // Submission configuration
            $table->string('submission_format', 20)->default('837P');
            // CHECK: 837P | 837I | custom
            $table->text('companion_guide_notes')->nullable();       // State-specific companion guide notes + deviations from standard
            $table->string('submission_endpoint', 500)->nullable();  // Clearinghouse/state portal URL
            $table->string('clearinghouse_name', 200)->nullable();   // e.g. 'Availity', 'Change Healthcare', 'State Portal'

            // Submission timing rules
            $table->unsignedSmallInteger('days_to_submit')->default(180);  // Filing deadline in days from service date
            $table->date('effective_date');                          // When this configuration became effective

            // Contact information for the state Medicaid agency
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 200)->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One config per tenant per state
            $table->unique(['tenant_id', 'state_code'], 'smc_tenant_state_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        // Enforce submission_format enum at DB level
        DB::statement("ALTER TABLE emr_state_medicaid_configs
            ADD CONSTRAINT smc_submission_format_check
            CHECK (submission_format IN ('837P', '837I', 'custom'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_state_medicaid_configs');
    }
};
