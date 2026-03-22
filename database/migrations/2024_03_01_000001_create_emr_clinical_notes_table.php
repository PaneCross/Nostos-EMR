<?php

// ─── Migration: emr_clinical_notes ─────────────────────────────────────────────
// Stores all clinical documentation for participants.
// Supports SOAP notes and 12 other structured note types.
// Signed notes are immutable — edits require creating an addendum (parent_note_id).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_clinical_notes', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant ownership ────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->constrained('shared_sites')
                ->cascadeOnDelete();

            // ── Note classification ───────────────────────────────────────────
            $table->enum('note_type', [
                'soap', 'progress_nursing', 'therapy_pt', 'therapy_ot', 'therapy_st',
                'social_work', 'behavioral_health', 'dietary', 'home_visit',
                'telehealth', 'idt_summary', 'incident', 'addendum',
            ]);
            $table->foreignId('authored_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->string('department', 30);   // dept of the author at time of writing

            // ── Workflow status ───────────────────────────────────────────────
            $table->enum('status', ['draft', 'signed', 'amended'])->default('draft');

            // ── Visit metadata ────────────────────────────────────────────────
            $table->enum('visit_type', ['in_center', 'home_visit', 'telehealth', 'phone'])
                ->default('in_center');
            $table->date('visit_date');
            $table->time('visit_time')->nullable();

            // ── SOAP content (nullable — only populated for soap note_type) ───
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();

            // ── Structured content for non-SOAP templates (jsonb) ────────────
            $table->jsonb('content')->nullable();

            // ── Signing provenance ────────────────────────────────────────────
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            // ── Addendum / amendment linkage ──────────────────────────────────
            // Self-referential FK: addenda point to the note they annotate
            $table->foreignId('parent_note_id')
                ->nullable()
                ->constrained('emr_clinical_notes')
                ->nullOnDelete();

            // ── Late entry compliance ─────────────────────────────────────────
            $table->boolean('is_late_entry')->default(false);
            $table->text('late_entry_reason')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'status']);
            $table->index(['participant_id', 'visit_date']);
            $table->index(['tenant_id', 'note_type']);
            $table->index(['authored_by_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_clinical_notes');
    }
};
