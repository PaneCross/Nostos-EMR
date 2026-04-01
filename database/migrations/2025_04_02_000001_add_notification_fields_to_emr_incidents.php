<?php

// ─── Migration: Add CMS/SMA Notification Fields to emr_incidents ─────────────
// W4-6 / GAP-08 / QW-10: 42 CFR §460.136 requires PACE programs to notify CMS
// and the State Medicaid Agency (SMA) of significant adverse events.
//
// Changes:
//   1. Add CMS/SMA notification tracking columns to emr_incidents
//   2. Add 'unexpected_death' to the incident_type CHECK constraint
//   3. Add regulatory_deadline (occurred_at + 72h, computed by IncidentService)
//
// Note on constraint update: PostgreSQL CHECK constraints cannot be modified
// in-place — we DROP the old constraint and ADD the new one with the expanded
// type list. down() restores the prior constraint (without 'unexpected_death').
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_incidents', function (Blueprint $table) {
            // CMS notification tracking (42 CFR §460.136)
            // cms_notification_required is auto-set by IncidentService (never from UI)
            // for types: abuse_neglect, hospitalization, er_visit, unexpected_death
            $table->boolean('cms_notification_required')->default(false)->after('cms_reportable');
            $table->timestamp('cms_notification_sent_at')->nullable()->after('cms_notification_required');
            $table->timestamp('sma_notification_sent_at')->nullable()->after('cms_notification_sent_at');
            $table->text('notification_notes')->nullable()->after('sma_notification_sent_at');

            // Regulatory deadline: computed by IncidentService as occurred_at + 72h
            // for CMS_NOTIFICATION_TYPES incidents. Null for non-reportable incidents.
            $table->timestamp('regulatory_deadline')->nullable()->after('notification_notes');
        });

        // ── Update incident_type CHECK constraint to include 'unexpected_death' ──
        // DROP old constraint (two possible names from original migration), ADD new one.
        DB::statement("
            ALTER TABLE emr_incidents
            DROP CONSTRAINT IF EXISTS emr_incidents_type_check
        ");
        DB::statement("
            ALTER TABLE emr_incidents
            DROP CONSTRAINT IF EXISTS emr_incidents_incident_type_check
        ");

        DB::statement("
            ALTER TABLE emr_incidents
            ADD CONSTRAINT emr_incidents_incident_type_check
            CHECK (incident_type IN (
                'fall',
                'medication_error',
                'elopement',
                'injury',
                'behavioral',
                'hospitalization',
                'er_visit',
                'infection',
                'abuse_neglect',
                'complaint',
                'unexpected_death',
                'other'
            ))
        ");
    }

    public function down(): void
    {
        Schema::table('emr_incidents', function (Blueprint $table) {
            $table->dropColumn([
                'cms_notification_required',
                'cms_notification_sent_at',
                'sma_notification_sent_at',
                'notification_notes',
                'regulatory_deadline',
            ]);
        });

        // Restore original constraint without 'unexpected_death'
        DB::statement("
            ALTER TABLE emr_incidents
            DROP CONSTRAINT IF EXISTS emr_incidents_incident_type_check
        ");

        DB::statement("
            ALTER TABLE emr_incidents
            ADD CONSTRAINT emr_incidents_incident_type_check
            CHECK (incident_type IN (
                'fall',
                'medication_error',
                'elopement',
                'injury',
                'behavioral',
                'hospitalization',
                'er_visit',
                'infection',
                'abuse_neglect',
                'complaint',
                'other'
            ))
        ");
    }
};
