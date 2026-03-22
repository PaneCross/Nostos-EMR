<?php

// ─── Migration: emr_idt_meetings ─────────────────────────────────────────────
// Interdisciplinary Team (IDT) meeting records.
// Each meeting has a participant review queue (emr_idt_participant_reviews).
//
// Meeting types reflect PACE operational cadence:
//   - daily:            stand-up for urgent participant changes
//   - weekly:           standard IDT review
//   - care_plan_review: 6-month CMS-required care plan approval meeting
//   - urgent:           unscheduled for acute changes
//
// Status lifecycle: scheduled → in_progress → completed
// Once completed, minutes and decisions are locked (enforced in controller).
//
// attendees (JSONB): array of user IDs present at the meeting
// decisions (JSONB): array of {participant_id, decision_text, action_items[]}
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_idt_meetings', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->nullable()
                ->constrained('shared_sites')
                ->nullOnDelete();

            // ── Scheduling ────────────────────────────────────────────────────
            $table->date('meeting_date');
            $table->time('meeting_time')->nullable();
            $table->enum('meeting_type', ['daily', 'weekly', 'care_plan_review', 'urgent'])
                ->default('weekly');

            // ── Facilitation ──────────────────────────────────────────────────
            $table->foreignId('facilitator_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->jsonb('attendees')->nullable();   // [user_id, ...]

            // ── Content ───────────────────────────────────────────────────────
            $table->text('minutes_text')->nullable();
            $table->jsonb('decisions')->nullable();   // [{participant_id, decision_text, action_items[]}]

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', ['scheduled', 'in_progress', 'completed'])->default('scheduled');

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['tenant_id', 'meeting_date', 'status'], 'idt_meetings_tenant_date_idx');
            $table->index(['site_id', 'meeting_date'],             'idt_meetings_site_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_idt_meetings');
    }
};
