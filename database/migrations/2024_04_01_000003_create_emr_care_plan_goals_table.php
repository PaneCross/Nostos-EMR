<?php

// ─── Migration: emr_care_plan_goals ──────────────────────────────────────────
// Domain-specific goals within a care plan. One row per discipline per plan.
// Each domain (medical, nursing, social, therapy_pt, etc.) has its own goal,
// measurable outcomes, and interventions.
//
// Domains map to the 14 PACE departments. A single care plan version may have
// up to 12 goals (one per domain enum value).
//
// Status lifecycle: active → met | modified | discontinued
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_care_plan_goals', function (Blueprint $table) {
            $table->id();

            // ── Parent care plan ──────────────────────────────────────────────
            $table->foreignId('care_plan_id')
                ->constrained('emr_care_plans')
                ->cascadeOnDelete();

            // ── Domain ────────────────────────────────────────────────────────
            $table->enum('domain', [
                'medical',
                'nursing',
                'social',
                'behavioral',
                'therapy_pt',
                'therapy_ot',
                'therapy_st',
                'dietary',
                'activities',
                'home_care',
                'transportation',
                'pharmacy',
            ]);

            // ── Goal content ──────────────────────────────────────────────────
            $table->text('goal_description');
            $table->date('target_date')->nullable();
            $table->text('measurable_outcomes')->nullable();
            $table->text('interventions')->nullable();

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', ['active', 'met', 'modified', 'discontinued'])
                ->default('active');

            // ── Authorship ────────────────────────────────────────────────────
            $table->foreignId('authored_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->foreignId('last_updated_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['care_plan_id', 'domain'], 'goals_plan_domain_idx');
            $table->index(['care_plan_id', 'status'], 'goals_plan_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_care_plan_goals');
    }
};
