<?php

// ─── CarePlan Model ───────────────────────────────────────────────────────────
// CMS-regulated individualized care plans for PACE participants.
//
// Lifecycle: draft → active (approved) → archived (superseded)
//            draft → under_review → active
//
// CMS requirement: review every 6 months (review_due_date = effective_date + 6m).
// Approval restricted to IDT Admin + Primary Care Admin (enforced in controller).
//
// Only one version per participant can be 'active' at any time.
// Creating a new version archives the current active plan.
//
// Domain-specific goals are stored in CarePlanGoal (emr_care_plan_goals).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarePlan extends Model
{
    use HasFactory;

    protected $table = 'emr_care_plans';

    public const STATUSES = ['draft', 'active', 'under_review', 'archived'];

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'version',
        'status',
        'effective_date',
        'review_due_date',
        'approved_by_user_id',
        'approved_at',
        'overall_goals_text',
    ];

    protected $casts = [
        'effective_date'  => 'date',
        'review_due_date' => 'date',
        'approved_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(CarePlanGoal::class, 'care_plan_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Only active care plans. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Care plans with review due within the next N days. */
    public function scopeDueForReview(Builder $query, int $days = 30): Builder
    {
        return $query->whereIn('status', ['active', 'under_review'])
            ->whereBetween('review_due_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Returns days remaining until review_due_date (negative = overdue).
     */
    public function daysUntilReview(): ?int
    {
        if (! $this->review_due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->review_due_date, false);
    }

    /**
     * True if this plan can be edited (only draft and under_review are editable).
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'under_review'], true);
    }

    /**
     * True if the user is permitted to approve this plan.
     * Approval requires IDT Admin or Primary Care Admin role.
     */
    public function canBeApprovedBy(User $user): bool
    {
        return $user->isAdmin()
            && in_array($user->department, ['idt', 'primary_care'], true);
    }

    /**
     * Approve this care plan. Sets status=active, effective_date=today,
     * review_due_date = effective_date + 6 months, and archives the previously
     * active plan for this participant.
     */
    public function approve(User $approver): self
    {
        // Archive the previously active plan
        self::where('participant_id', $this->participant_id)
            ->where('status', 'active')
            ->where('id', '!=', $this->id)
            ->update(['status' => 'archived']);

        $effectiveDate = Carbon::today();

        $this->update([
            'status'               => 'active',
            'effective_date'       => $effectiveDate,
            'review_due_date'      => $effectiveDate->copy()->addMonths(6),
            'approved_by_user_id'  => $approver->id,
            'approved_at'          => now(),
        ]);

        return $this->refresh();
    }

    /**
     * Create a new draft version from this plan, copying all goals.
     * The current plan is set to 'under_review'; the copy is a new draft.
     */
    public function createNewVersion(User $author): self
    {
        $nextVersion = self::where('participant_id', $this->participant_id)->max('version') + 1;

        $newPlan = self::create([
            'participant_id'    => $this->participant_id,
            'tenant_id'         => $this->tenant_id,
            'version'           => $nextVersion,
            'status'            => 'draft',
            'overall_goals_text' => $this->overall_goals_text,
        ]);

        // Copy all active goals to the new version
        foreach ($this->goals()->where('status', 'active')->get() as $goal) {
            CarePlanGoal::create([
                'care_plan_id'          => $newPlan->id,
                'domain'                => $goal->domain,
                'goal_description'      => $goal->goal_description,
                'target_date'           => $goal->target_date,
                'measurable_outcomes'   => $goal->measurable_outcomes,
                'interventions'         => $goal->interventions,
                'status'                => 'active',
                'authored_by_user_id'   => $author->id,
                'last_updated_by_user_id' => $author->id,
            ]);
        }

        // Archive the source plan — the new draft supersedes it
        $this->update(['status' => 'archived']);

        return $newPlan;
    }
}
