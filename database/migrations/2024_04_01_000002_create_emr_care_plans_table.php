<?php

// ─── Migration: emr_care_plans ────────────────────────────────────────────────
// CMS-regulated individualized care plans for PACE participants.
// A participant may have multiple versions; only one can be 'active' at a time.
//
// Lifecycle: draft → active (approved) → archived (superseded by new version)
//            draft → under_review → active
//
// CMS requirement: care plan must be reviewed every 6 months (review_due_date).
// Approval is restricted to IDT Admin + Primary Care Admin (enforced in controller).
//
// Each care plan has domain-specific goals stored in emr_care_plan_goals.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_care_plans', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Versioning ────────────────────────────────────────────────────
            // Increments each time a new version is created from an existing plan.
            $table->unsignedSmallInteger('version')->default(1);

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', ['draft', 'active', 'under_review', 'archived'])
                ->default('draft');

            // ── Dates ─────────────────────────────────────────────────────────
            $table->date('effective_date')->nullable();
            $table->date('review_due_date')->nullable();   // effective_date + 6 months (CMS)

            // ── Approval ──────────────────────────────────────────────────────
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // ── Content ───────────────────────────────────────────────────────
            $table->text('overall_goals_text')->nullable();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'status'],           'care_plans_participant_status_idx');
            $table->index(['tenant_id', 'review_due_date'],       'care_plans_review_due_idx');
            $table->unique(['participant_id', 'version'],         'care_plans_participant_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_care_plans');
    }
};
