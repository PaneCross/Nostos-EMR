<?php

// ─── Migration: Create emr_significant_change_events ─────────────────────────
// W4-6 / GAP-10 / QW-12: 42 CFR §460.104(b) requires IDT reassessment within
// 30 days of a significant change in health status.
//
// A significant change event is created when:
//   - A participant is admitted to a hospital (HL7 ADT A01 via ProcessHl7AdtJob)
//   - A fall results in injuries_sustained=true (via IncidentService)
//   - Manually by IDT staff for functional decline or other triggers
//
// idt_review_due_date = trigger_date + 30 days (see SignificantChangeEvent::IDT_REVIEW_DUE_DAYS)
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_significant_change_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');

            // What triggered the significant change
            $table->string('trigger_type', 50);   // hospitalization, fall_with_injury, functional_decline, other
            $table->date('trigger_date');

            // Source system that created this event
            $table->string('trigger_source', 50)->default('manual');   // manual, adt_connector, incident_service

            // Reference to the incident or integration log that caused this event
            $table->unsignedBigInteger('source_incident_id')->nullable();   // FK to emr_incidents
            $table->unsignedBigInteger('source_integration_log_id')->nullable();   // FK to emr_integration_log

            // IDT review deadline (42 CFR §460.104(b): within 30 days of trigger)
            $table->date('idt_review_due_date');

            // Completion tracking
            $table->string('status', 20)->default('pending');   // pending, completed, waived
            $table->timestamp('review_completed_at')->nullable();
            $table->unsignedBigInteger('review_completed_by_user_id')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Who created this event
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['participant_id', 'status']);
            $table->index('idt_review_due_date');

            // FKs
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');
            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');
            $table->foreign('source_incident_id')->references('id')->on('emr_incidents')->nullOnDelete();
            $table->foreign('review_completed_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
        });

        // PostgreSQL CHECK constraints
        DB::statement("
            ALTER TABLE emr_significant_change_events
            ADD CONSTRAINT sce_trigger_type_check
            CHECK (trigger_type IN ('hospitalization', 'fall_with_injury', 'functional_decline', 'other'))
        ");

        DB::statement("
            ALTER TABLE emr_significant_change_events
            ADD CONSTRAINT sce_trigger_source_check
            CHECK (trigger_source IN ('manual', 'adt_connector', 'incident_service'))
        ");

        DB::statement("
            ALTER TABLE emr_significant_change_events
            ADD CONSTRAINT sce_status_check
            CHECK (status IN ('pending', 'completed', 'waived'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_significant_change_events');
    }
};
