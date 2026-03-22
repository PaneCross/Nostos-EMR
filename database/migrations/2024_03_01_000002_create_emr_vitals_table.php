<?php

// ─── Migration: emr_vitals ──────────────────────────────────────────────────────
// Append-only vitals records. No soft deletes — each row is an immutable snapshot.
// All measurement columns are nullable (not all vitals captured every visit).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_vitals', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant ownership ────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();

            // ── Blood pressure ────────────────────────────────────────────────
            $table->smallInteger('bp_systolic')->nullable();    // mmHg
            $table->smallInteger('bp_diastolic')->nullable();   // mmHg

            // ── Other vital signs ─────────────────────────────────────────────
            $table->smallInteger('pulse')->nullable();           // bpm
            $table->smallInteger('respiratory_rate')->nullable();// breaths/min
            $table->decimal('temperature_f', 4, 1)->nullable(); // °F
            $table->smallInteger('o2_saturation')->nullable();  // %

            // ── Anthropometrics ───────────────────────────────────────────────
            $table->decimal('weight_lbs', 5, 1)->nullable();
            $table->decimal('height_in', 4, 1)->nullable();     // rarely changes

            // ── Scored measures ───────────────────────────────────────────────
            $table->tinyInteger('pain_score')->nullable();       // 0–10
            $table->smallInteger('blood_glucose')->nullable();   // mg/dL

            // ── Context ───────────────────────────────────────────────────────
            $table->enum('position', ['sitting', 'standing', 'lying'])->default('sitting');
            $table->text('notes')->nullable();

            // ── Append-only: only created_at, no updated_at ───────────────────
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'recorded_at']);
            $table->index(['tenant_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_vitals');
    }
};
