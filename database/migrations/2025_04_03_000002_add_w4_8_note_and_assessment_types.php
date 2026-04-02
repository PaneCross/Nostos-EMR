<?php

// ─── Migration 95 — W4-8: New Note and Assessment Types ──────────────────────
// Extends the note_type and assessment_type CHECK constraints to support:
//   - transition_of_care: auto-generated from HL7 ADT A01/A03 events (draft)
//   - podiatry: required PACE service per 42 CFR §460.92
//   - fall_history: fall history assessment with alert when falls_12_months >= 2
//   - lace_plus_index: readmission risk tool (dual threshold: >=5 warning, >=10 critical)
//
// PostgreSQL CHECK constraints cannot be modified in-place — drop and re-add.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Extend emr_clinical_notes.note_type ──────────────────────────────
        DB::statement('ALTER TABLE emr_clinical_notes DROP CONSTRAINT IF EXISTS emr_clinical_notes_note_type_check');
        DB::statement("
            ALTER TABLE emr_clinical_notes
            ADD CONSTRAINT emr_clinical_notes_note_type_check
            CHECK (note_type IN (
                'soap', 'progress_nursing', 'therapy_pt', 'therapy_ot', 'therapy_st',
                'social_work', 'behavioral_health', 'dietary', 'home_visit',
                'telehealth', 'idt_summary', 'incident', 'addendum',
                'transition_of_care', 'podiatry'
            ))
        ");

        // ── Extend emr_assessments.assessment_type ───────────────────────────
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');
        DB::statement("
            ALTER TABLE emr_assessments
            ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
                'braden_scale', 'moca_cognitive', 'oral_health',
                'fall_history', 'lace_plus_index'
            ))
        ");
    }

    public function down(): void
    {
        // Restore pre-W4-8 note_type constraint
        DB::statement('ALTER TABLE emr_clinical_notes DROP CONSTRAINT IF EXISTS emr_clinical_notes_note_type_check');
        DB::statement("
            ALTER TABLE emr_clinical_notes
            ADD CONSTRAINT emr_clinical_notes_note_type_check
            CHECK (note_type IN (
                'soap', 'progress_nursing', 'therapy_pt', 'therapy_ot', 'therapy_st',
                'social_work', 'behavioral_health', 'dietary', 'home_visit',
                'telehealth', 'idt_summary', 'incident', 'addendum'
            ))
        ");

        // Restore pre-W4-8 assessment_type constraint
        DB::statement('ALTER TABLE emr_assessments DROP CONSTRAINT IF EXISTS emr_assessments_assessment_type_check');
        DB::statement("
            ALTER TABLE emr_assessments
            ADD CONSTRAINT emr_assessments_assessment_type_check
            CHECK (assessment_type IN (
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
                'braden_scale', 'moca_cognitive', 'oral_health'
            ))
        ");
    }
};
