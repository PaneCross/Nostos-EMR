<?php

// ─── Migration 78: emr_grievances ─────────────────────────────────────────────
// Grievance tracking per 42 CFR §460.120–§460.121.
// CMS requires PACE organizations to maintain a grievance process, investigate
// all grievances, resolve standard grievances within 30 days, urgent within 72h,
// and notify participants of resolution.
//
// filed_by_type covers both system users and external parties (family, anonymous).
// priority=urgent triggers immediate QA alert and 72-hour resolution clock.
// cms_reportable is set manually by QA admin when the grievance meets CMS
// reporting criteria (e.g. discrimination, serious safety concerns).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_grievances', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant context ───────────────────────────────
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');

            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');
            $table->foreign('site_id')->references('id')->on('shared_sites')->onDelete('cascade');

            // ── Filer information ──────────────────────────────────────────
            $table->string('filed_by_name');    // Free text — may not be a system user
            $table->string('filed_by_type');    // participant | family_member | caregiver | legal_representative | staff | anonymous
            $table->timestamp('filed_at');      // When the grievance was filed (may differ from created_at)
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->foreign('received_by_user_id')->references('id')->on('shared_users')->nullOnDelete();

            // ── Grievance details ──────────────────────────────────────────
            $table->string('category');         // quality_of_care | access_to_services | staff_conduct | billing | discrimination | privacy | transportation | other
            $table->text('description');

            // ── Workflow ───────────────────────────────────────────────────
            $table->string('status')->default('open');      // open | under_review | resolved | escalated | withdrawn
            $table->string('priority')->default('standard'); // standard | urgent
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->foreign('assigned_to_user_id')->references('id')->on('shared_users')->nullOnDelete();

            // ── Investigation & resolution ─────────────────────────────────
            $table->text('investigation_notes')->nullable();
            $table->text('resolution_text')->nullable();
            $table->date('resolution_date')->nullable();
            $table->text('escalation_reason')->nullable();

            // ── Participant notification tracking (CMS §460.120(d)) ────────
            $table->timestamp('participant_notified_at')->nullable();
            $table->string('notification_method')->nullable(); // verbal | written | phone | mail

            // ── CMS reportability ──────────────────────────────────────────
            $table->boolean('cms_reportable')->default(false);
            $table->timestamp('cms_reported_at')->nullable();

            // ── Audit ──────────────────────────────────────────────────────
            $table->softDeletes();
            $table->timestamps();
        });

        // CHECK constraints for enum-like columns
        \DB::statement("ALTER TABLE emr_grievances ADD CONSTRAINT emr_grievances_filed_by_type_check CHECK (filed_by_type IN ('participant','family_member','caregiver','legal_representative','staff','anonymous'))");
        \DB::statement("ALTER TABLE emr_grievances ADD CONSTRAINT emr_grievances_category_check CHECK (category IN ('quality_of_care','access_to_services','staff_conduct','billing','discrimination','privacy','transportation','other'))");
        \DB::statement("ALTER TABLE emr_grievances ADD CONSTRAINT emr_grievances_status_check CHECK (status IN ('open','under_review','resolved','escalated','withdrawn'))");
        \DB::statement("ALTER TABLE emr_grievances ADD CONSTRAINT emr_grievances_priority_check CHECK (priority IN ('standard','urgent'))");
        \DB::statement("ALTER TABLE emr_grievances ADD CONSTRAINT emr_grievances_notification_method_check CHECK (notification_method IS NULL OR notification_method IN ('verbal','written','phone','mail'))");

        // Performance indexes
        \DB::statement('CREATE INDEX emr_grievances_tenant_status_idx ON emr_grievances (tenant_id, status)');
        \DB::statement('CREATE INDEX emr_grievances_participant_idx ON emr_grievances (participant_id)');
        \DB::statement('CREATE INDEX emr_grievances_filed_at_idx ON emr_grievances (filed_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_grievances');
    }
};
