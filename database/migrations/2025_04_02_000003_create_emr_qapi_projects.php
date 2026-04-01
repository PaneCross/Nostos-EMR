<?php

// ─── Migration: Create emr_qapi_projects ─────────────────────────────────────
// W4-6 / QAPI Module: 42 CFR §460.136–§460.140 requires PACE organizations to
// maintain an active Quality Assessment and Performance Improvement (QAPI) program
// with at least 2 active quality improvement (QI) projects at any time of the year.
//
// QAPI project lifecycle:
//   planning → active → remeasuring → completed
//   Any non-completed status → suspended
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_qapi_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Project identity
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('aim_statement', 500)->nullable();   // What improvement is targeted

            // Domain/focus area (for filtering)
            $table->string('domain', 50)->default('clinical_outcomes');   // clinical_outcomes, safety, access, satisfaction, efficiency

            // Lifecycle
            $table->string('status', 20)->default('planning');   // planning, active, remeasuring, completed, suspended
            $table->date('start_date');
            $table->date('target_completion_date')->nullable();
            $table->date('actual_completion_date')->nullable();

            // Baseline and target metrics
            $table->string('baseline_metric', 200)->nullable();   // e.g. "42% of participants had falls in Q3"
            $table->string('target_metric', 200)->nullable();      // e.g. "Reduce falls by 20% in 6 months"
            $table->string('current_metric', 200)->nullable();     // Updated during remeasurement

            // Team
            $table->unsignedBigInteger('project_lead_user_id')->nullable();   // Accountable person (FK to shared_users)
            $table->json('team_member_ids')->default('[]');                    // JSON array of user IDs

            // Progress notes
            $table->text('interventions')->nullable();   // What interventions were implemented
            $table->text('findings')->nullable();        // Results and learnings

            // Who created this project
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'domain']);

            // FKs
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');
            $table->foreign('project_lead_user_id')->references('id')->on('shared_users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
        });

        // PostgreSQL CHECK constraints
        DB::statement("
            ALTER TABLE emr_qapi_projects
            ADD CONSTRAINT qapi_status_check
            CHECK (status IN ('planning', 'active', 'remeasuring', 'completed', 'suspended'))
        ");

        DB::statement("
            ALTER TABLE emr_qapi_projects
            ADD CONSTRAINT qapi_domain_check
            CHECK (domain IN ('clinical_outcomes', 'safety', 'access', 'satisfaction', 'efficiency'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_qapi_projects');
    }
};
