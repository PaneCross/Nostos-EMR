<?php

// ─── Migration: emr_assessments ────────────────────────────────────────────────
// Structured clinical assessments (PHQ-9, MMSE, Morse fall scale, etc.).
// Responses stored as jsonb keyed by assessment_type template fields.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_assessments', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant ownership ────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('authored_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->string('department', 30);   // dept of the author at time of assessment

            // ── Assessment classification ─────────────────────────────────────
            $table->enum('assessment_type', [
                'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
                'phq9_depression', 'gad7_anxiety', 'nutritional',
                'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
            ]);

            // ── Structured responses and computed score ───────────────────────
            $table->jsonb('responses');         // keyed by field name from template
            $table->smallInteger('score')->nullable();  // e.g., PHQ-9: 0-27, MMSE: 0-30

            // ── Scheduling ────────────────────────────────────────────────────
            $table->timestamp('completed_at');
            $table->date('next_due_date')->nullable();

            // ── Threshold breach tracking ─────────────────────────────────────
            // Which response fields breached their defined thresholds
            $table->jsonb('threshold_flags')->nullable();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'assessment_type']);
            $table->index(['participant_id', 'next_due_date']);
            $table->index(['tenant_id', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_assessments');
    }
};
