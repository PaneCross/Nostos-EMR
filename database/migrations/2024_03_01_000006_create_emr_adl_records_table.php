<?php

// ─── Migration: emr_adl_records ─────────────────────────────────────────────────
// Append-only ADL (Activities of Daily Living) observations.
// threshold_breached is computed on insert by AdlThresholdService and never updated.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_adl_records', function (Blueprint $table) {
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

            // ── ADL observation ───────────────────────────────────────────────
            $table->enum('adl_category', [
                'bathing', 'dressing', 'grooming', 'toileting', 'transferring',
                'ambulation', 'eating', 'continence', 'medication_management', 'communication',
            ]);
            // Ordered from most independent to most dependent (same order used for threshold comparison)
            $table->enum('independence_level', [
                'independent', 'supervision', 'limited_assist',
                'extensive_assist', 'total_dependent',
            ]);

            $table->string('assistive_device_used', 100)->nullable();
            $table->text('notes')->nullable();

            // ── Threshold breach ──────────────────────────────────────────────
            // Set true by AdlThresholdService on insert if level exceeds threshold
            // TODO Phase 4: when true, also create emr_alert for primary_care + social_work
            $table->boolean('threshold_breached')->default(false);

            // ── Append-only: only created_at ──────────────────────────────────
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'adl_category', 'recorded_at']);
            $table->index(['participant_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_adl_records');
    }
};
