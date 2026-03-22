<?php

// ─── Migration: emr_adl_thresholds ──────────────────────────────────────────────
// One threshold per participant per ADL category.
// When a new ADL record's independence_level is worse than the threshold,
// threshold_breached is set on the record and a Phase 4 alert will be triggered.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_adl_thresholds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();

            $table->enum('adl_category', [
                'bathing', 'dressing', 'grooming', 'toileting', 'transferring',
                'ambulation', 'eating', 'continence', 'medication_management', 'communication',
            ]);

            // The alert threshold — if a new ADL record is worse than this level, breach fires
            $table->enum('threshold_level', [
                'independent', 'supervision', 'limited_assist',
                'extensive_assist', 'total_dependent',
            ]);

            $table->foreignId('set_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->timestamp('set_at')->useCurrent();

            // ── Enforce one threshold per participant per category ─────────────
            $table->unique(['participant_id', 'adl_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_adl_thresholds');
    }
};
