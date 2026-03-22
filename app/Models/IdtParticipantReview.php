<?php

// ─── IdtParticipantReview Model ───────────────────────────────────────────────
// Records the review of a single participant within an IDT meeting.
// One row per participant per meeting.
//
// action_items (JSONB): [{description, assigned_to_dept, due_date}]
// queue_order: used by the meeting UI for drag-to-reorder
// reviewed_at: null = queued but not yet reviewed in this meeting
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdtParticipantReview extends Model
{
    use HasFactory;

    protected $table = 'emr_idt_participant_reviews';

    protected $fillable = [
        'meeting_id',
        'participant_id',
        'presenting_discipline',
        'summary_text',
        'action_items',
        'status_change_noted',
        'queue_order',
        'reviewed_at',
    ];

    protected $casts = [
        'action_items'        => 'array',
        'status_change_noted' => 'boolean',
        'reviewed_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(IdtMeeting::class, 'meeting_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Reviews not yet completed in this meeting. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }

    /** Reviews that have been completed. */
    public function scopeReviewed(Builder $query): Builder
    {
        return $query->whereNotNull('reviewed_at');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True if this participant has been reviewed in the current meeting. */
    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** Mark this participant as reviewed. */
    public function markReviewed(): self
    {
        $this->update(['reviewed_at' => now()]);
        return $this->refresh();
    }
}
