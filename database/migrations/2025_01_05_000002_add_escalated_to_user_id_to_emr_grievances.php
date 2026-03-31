<?php

// ─── Migration: add_escalated_to_user_id_to_emr_grievances ───────────────────
// Adds a nullable FK to track which specific staff member a grievance was
// escalated to. Replaces the department-broadcast-only model with named
// accountability.
//
// CMS survey reviewers ask "who reviewed this escalated grievance?" —
// a named person (compliance officer, medical director) must be in the file.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_grievances', function (Blueprint $table) {
            // Nullable — not all escalations target a specific named user
            $table->unsignedBigInteger('escalated_to_user_id')->nullable()->after('escalation_reason');
            $table->foreign('escalated_to_user_id')
                  ->references('id')
                  ->on('shared_users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_grievances', function (Blueprint $table) {
            $table->dropForeign(['escalated_to_user_id']);
            $table->dropColumn('escalated_to_user_id');
        });
    }
};
