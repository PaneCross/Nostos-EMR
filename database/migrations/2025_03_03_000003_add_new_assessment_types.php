<?php

// ─── Migration: add Braden, MoCA, OHAT assessment types (W4-4) ──────────────
// Expands the emr_assessments.assessment_type CHECK constraint to include:
//   braden_scale  — Braden Scale for Predicting Pressure Sore Risk (6 subscales, 6-23)
//   moca_cognitive — Montreal Cognitive Assessment 30-point screen
//   oral_health   — Oral Health Assessment Tool (OHAT) 8-item screen
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');

        DB::statement("ALTER TABLE emr_assessments ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
                'braden_scale', 'moca_cognitive', 'oral_health'
            ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');

        DB::statement("ALTER TABLE emr_assessments ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom'
            ))");
    }
};
