<?php

// ─── Migration: emr_incidents ──────────────────────────────────────────────────
// Tracks adverse events and safety incidents in the PACE program.
//
// CMS/PACE Rule:
//   Root cause analysis (RCA) is mandatory for: falls, medication errors,
//   elopements, hospitalizations, ER visits, and abuse/neglect reports.
//   The rca_required flag is auto-set by IncidentService (never by UI input).
//
// Status lifecycle: open → under_review → rca_in_progress → closed
//   Incidents with rca_required=true cannot be closed until rca_completed=true.
//
// cms_reportable: flagged when the incident meets CMS HPMS reporting criteria.
//   cms_reported_at is set when the report is submitted (Phase 6C+).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_incidents', function (Blueprint $table) {
            $table->id();

            // Tenant + participant scoping
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();

            // Incident classification
            $table->string('incident_type', 30); // CHECK constraint below
            $table->timestamp('occurred_at');
            $table->string('location_of_incident', 200)->nullable();

            // Reporting
            $table->foreignId('reported_by_user_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('shared_users');
            $table->timestamp('reported_at');

            // Description + immediate response
            $table->text('description');
            $table->text('immediate_actions_taken')->nullable();

            // Injuries
            $table->boolean('injuries_sustained')->default(false);
            $table->text('injury_description')->nullable();

            // Witnesses (name + contact, stored as JSON array)
            $table->jsonb('witnesses')->nullable();

            // RCA (Root Cause Analysis) — CMS-required for certain incident types
            // rca_required is auto-computed by IncidentService, never set by UI
            $table->boolean('rca_required')->default(false);
            $table->boolean('rca_completed')->default(false);
            $table->text('rca_text')->nullable();
            $table->foreignId('rca_completed_by_user_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('shared_users');

            // CMS HPMS reporting
            $table->boolean('cms_reportable')->default(false);
            $table->timestamp('cms_reported_at')->nullable();

            // Workflow status
            $table->string('status', 20)->default('open'); // CHECK constraint below

            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ──────────────────────────────────────────────────────
            $table->index(['tenant_id', 'status']);
            $table->index(['participant_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'incident_type']);
            $table->index(['reported_by_user_id', 'reported_at']);
        });

        // ── CHECK constraints (PostgreSQL) ───────────────────────────────────
        DB::statement("ALTER TABLE emr_incidents ADD CONSTRAINT emr_incidents_type_check
            CHECK (incident_type IN (
                'fall','medication_error','elopement','injury','behavioral',
                'hospitalization','er_visit','infection','abuse_neglect','complaint','other'
            ))");

        DB::statement("ALTER TABLE emr_incidents ADD CONSTRAINT emr_incidents_status_check
            CHECK (status IN ('open','under_review','rca_in_progress','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_incidents');
    }
};
