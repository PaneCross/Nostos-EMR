<?php

// ─── Migration: add_designations_to_shared_users ──────────────────────────────
// Adds a JSONB `designations` column to shared_users.
//
// Designations are sub-role labels that identify specific functional roles
// within a department (e.g. medical_director, compliance_officer). Unlike
// departments (access control), designations are used for targeted alerting
// and workflow routing — so the right named person is notified when needed.
//
// Example: when a grievance is escalated, the QA Compliance Officer is
// targeted directly. When an SDR is overdue, the Medical Director is pinged.
//
// Storage: JSONB array of designation string keys, e.g. ["compliance_officer"]
// See User::DESIGNATIONS for valid values.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            // JSONB array of designation keys — defaults to empty array
            $table->jsonb('designations')->default('[]')->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            $table->dropColumn('designations');
        });
    }
};
