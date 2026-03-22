<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Migration: emr_participant_risk_scores ────────────────────────────────────
// Stores annual CMS-HCC risk adjustment data per participant per payment year.
// One record per participant per payment_year (unique constraint enforced).
//
// score_source enum values:
//   'cms_import'  — imported from CMS remittance/rate notice (authoritative)
//   'calculated'  — computed locally by RiskAdjustmentService from emr_problems
//   'manual'      — entered by finance staff
//
// Phase 9C — Part A (Risk Adjustment Tracking)
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participant_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // CMS-HCC risk adjustment fields
            $table->unsignedSmallInteger('payment_year');          // e.g. 2025
            $table->decimal('risk_score', 8, 4)->nullable();       // composite RAF score (e.g. 1.2345)
            $table->decimal('frailty_score', 8, 4)->nullable();    // PACE frailty adjuster component

            // Diagnosis capture metrics
            $table->jsonb('hcc_categories')->default('[]');         // array of captured HCC category codes
            $table->unsignedSmallInteger('diagnoses_submitted')->default(0);  // ICD-10 codes submitted to CMS
            $table->unsignedSmallInteger('diagnoses_accepted')->default(0);   // accepted by CMS EDS

            // Source of this risk score record
            $table->string('score_source', 20)->default('calculated');
            // CHECK: cms_import | calculated | manual
            $table->date('effective_date')->nullable();             // CMS effective date for rate year
            $table->timestamp('imported_at')->nullable();           // when imported from CMS file (null if calculated)

            $table->timestamps();

            // One risk score record per participant per payment year
            $table->unique(['participant_id', 'payment_year'], 'urs_participant_year_unique');
            $table->index(['tenant_id', 'payment_year']);
        });

        // Enforce score_source enum at DB level
        DB::statement("ALTER TABLE emr_participant_risk_scores
            ADD CONSTRAINT urs_score_source_check
            CHECK (score_source IN ('cms_import', 'calculated', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participant_risk_scores');
    }
};
