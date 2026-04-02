<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── emr_lab_results ─────────────────────────────────────────────────────────
// Structured lab result records. May be sourced from HL7 ORU inbound messages
// (via ProcessLabResultJob) or manual entry by clinical staff.
//
// W5-2: Lab Results Viewer — resolves GAP from audit (2026-04-01):
//   "Lab Results Viewer — HL7 ORU ingested into integration log but not
//    surfaced in participant chart."
//
// Key design decisions:
//   - integration_log_id nullable FK — present for HL7-sourced results; null for manual
//   - source enum enforces origin traceability
//   - overall_status mirrors HL7 OBR-25 observation result status values
//   - reviewed_by_user_id tracks clinical review (42 CFR §460.98 care plan loop)
//   - SoftDeletes: lab results are part of the clinical record (HIPAA — never hard delete)
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_lab_results', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');

            // Nullable: only set when sourced from HL7 inbound integration log
            $table->unsignedBigInteger('integration_log_id')->nullable();

            // Test identity
            $table->string('test_name');
            $table->string('test_code', 50)->nullable();   // LOINC or local code

            // Timestamps from the lab (not Laravel's created_at)
            $table->timestamp('collected_at');
            $table->timestamp('resulted_at')->nullable();

            // Ordering / performing context
            $table->string('ordering_provider_name', 200)->nullable();
            $table->string('performing_facility', 200)->nullable();

            // Origin tracking
            $table->string('source', 30)->default('manual_entry');
            // Valid: hl7_inbound, manual_entry

            // HL7 OBR-25 result status
            $table->string('overall_status', 30)->default('final');
            // Valid: final, preliminary, corrected, cancelled

            // Summary abnormal flag (true if ANY component is abnormal/critical)
            $table->boolean('abnormal_flag')->default(false);

            // Clinical review
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ────────────────────────────────────────────────────────
            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('integration_log_id')->references('id')->on('emr_integration_log')->nullOnDelete();
            $table->foreign('reviewed_by_user_id')->references('id')->on('shared_users')->nullOnDelete();

            $table->index(['tenant_id', 'participant_id', 'collected_at']);
            $table->index(['tenant_id', 'abnormal_flag']);
            $table->index(['tenant_id', 'reviewed_at']);
        });

        // PostgreSQL CHECK constraints for enum columns
        DB::statement("ALTER TABLE emr_lab_results ADD CONSTRAINT emr_lab_results_source_check CHECK (source IN ('hl7_inbound','manual_entry'))");
        DB::statement("ALTER TABLE emr_lab_results ADD CONSTRAINT emr_lab_results_status_check CHECK (overall_status IN ('final','preliminary','corrected','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_lab_results');
    }
};
