<?php

// ─── IdtMeeting Model ─────────────────────────────────────────────────────────
// IDT (Interdisciplinary Team) meeting records.
//
// Meeting lifecycle: scheduled → in_progress → completed
// Once completed, minutes and decisions are locked (controller enforces this).
//
// attendees (JSONB): [user_id, ...]
// decisions (JSONB): [{participant_id, decision_text, action_items: []}]
//
// Participant reviews are stored in IdtParticipantReview (emr_idt_participant_reviews).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdtMeeting extends Model
{
    use HasFactory;

    protected $table = 'emr_idt_meetings';

    public const TYPES    = ['daily', 'weekly', 'care_plan_review', 'urgent'];
    public const STATUSES = ['scheduled', 'in_progress', 'completed'];

    // Human-readable meeting type labels
    public const TYPE_LABELS = [
        'daily'             => 'Daily Stand-up',
        'weekly'            => 'Weekly IDT Review',
        'care_plan_review'  => 'Care Plan Review',
        'urgent'            => 'Urgent Meeting',
    ];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'meeting_date',
        'meeting_time',
        'meeting_type',
        'facilitator_user_id',
        'attendees',
        'minutes_text',
        'decisions',
        'status',
    ];

    protected $casts = [
        'meeting_date' => 'date',
        'attendees'    => 'array',
        'decisions'    => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_user_id');
    }

    public function participantReviews(): HasMany
    {
        return $this->hasMany(IdtParticipantReview::class, 'meeting_id')
            ->orderBy('queue_order');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['scheduled', 'in_progress'])
            ->where('meeting_date', '>=', now()->toDateString());
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->where('meeting_date', now()->toDateString());
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True if this meeting can still be edited (not yet completed). */
    public function isLocked(): bool
    {
        return $this->status === 'completed';
    }

    /** Human-readable meeting type label. */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->meeting_type]
            ?? ucwords(str_replace('_', ' ', $this->meeting_type));
    }

    /** Tailwind status badge classes. */
    public function statusClasses(): string
    {
        return match ($this->status) {
            'scheduled'   => 'bg-blue-50 text-blue-700',
            'in_progress' => 'bg-amber-50 text-amber-700',
            'completed'   => 'bg-green-50 text-green-700',
            default       => 'bg-gray-50 text-gray-600',
        };
    }
}
