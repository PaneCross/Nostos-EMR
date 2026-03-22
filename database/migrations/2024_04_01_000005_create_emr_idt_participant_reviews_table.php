<?php

// ─── Migration: emr_idt_participant_reviews ───────────────────────────────────
// Individual participant review records within an IDT meeting.
// One row per participant reviewed in a given meeting.
//
// action_items (JSONB): [{description, assigned_to_dept, due_date}]
// reviewed_at: null if participant is queued but not yet reviewed
// status_change_noted: true if the IDT noted a change in participant status
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_idt_participant_reviews', function (Blueprint $table) {
            $table->id();

            // ── Parent meeting ────────────────────────────────────────────────
            $table->foreignId('meeting_id')
                ->constrained('emr_idt_meetings')
                ->cascadeOnDelete();

            // ── Participant ───────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();

            // ── Review content ────────────────────────────────────────────────
            $table->string('presenting_discipline', 50)->nullable();  // e.g. 'primary_care'
            $table->text('summary_text')->nullable();
            $table->jsonb('action_items')->nullable();   // [{description, assigned_to_dept, due_date}]
            $table->boolean('status_change_noted')->default(false);

            // ── Queue tracking ────────────────────────────────────────────────
            $table->unsignedSmallInteger('queue_order')->default(0);  // drag-to-reorder in meeting UI
            $table->timestamp('reviewed_at')->nullable();   // null = queued but not yet reviewed

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['meeting_id', 'queue_order'],  'reviews_meeting_order_idx');
            $table->index(['participant_id', 'meeting_id'], 'reviews_participant_meeting_idx');

            // One participant per meeting
            $table->unique(['meeting_id', 'participant_id'], 'reviews_meeting_participant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_idt_participant_reviews');
    }
};
