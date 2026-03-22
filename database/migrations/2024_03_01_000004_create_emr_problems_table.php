<?php

// ─── Migration: emr_problems ────────────────────────────────────────────────────
// Participant problem list (active diagnoses, chronic conditions, resolved problems).
// ICD-10 code + description stored directly — no FK to lookup table so historical
// records remain intact even if the lookup table changes.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_problems', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant ownership ────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── ICD-10 diagnosis ──────────────────────────────────────────────
            // Stored inline (not FK) so records survive lookup table updates
            $table->string('icd10_code', 10);
            $table->string('icd10_description', 200);

            // ── Timeline ──────────────────────────────────────────────────────
            $table->date('onset_date')->nullable();
            $table->date('resolved_date')->nullable();

            // ── Status workflow ───────────────────────────────────────────────
            $table->enum('status', ['active', 'resolved', 'chronic', 'ruled_out'])
                ->default('active');

            // ── Attribution ───────────────────────────────────────────────────
            $table->foreignId('added_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->foreignId('last_reviewed_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->timestamp('last_reviewed_at')->nullable();

            // ── Primary diagnosis flag ────────────────────────────────────────
            $table->boolean('is_primary_diagnosis')->default(false);

            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'status']);
            $table->index(['tenant_id', 'icd10_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_problems');
    }
};
