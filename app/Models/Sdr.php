<?php

// ─── Sdr (Service Delivery Request) Model ────────────────────────────────────
// Cross-department requests for services, referrals, orders, and care changes.
//
// 72-Hour Rule (CMS/PACE operational requirement):
//   - due_at is ALWAYS = submitted_at + 72 hours
//   - This is enforced in boot() — setting due_at to anything else throws
//   - SdrDeadlineEnforcementJob runs every 15 min and:
//       - Fires warning alert at 24h remaining
//       - Fires urgent alert at 8h remaining
//       - Escalates (escalated=true) and fires critical alert when overdue
//
// All deletes are soft deletes (deleted_at preserved for audit trail).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sdr extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_sdrs';

    public const REQUEST_TYPES = [
        'lab_order',
        'referral',
        'home_care_visit',
        'transport_request',
        'equipment_dme',
        'pharmacy_change',
        'assessment_request',
        'care_plan_update',
        'other',
    ];

    public const PRIORITIES = ['routine', 'urgent', 'emergent'];
    public const STATUSES   = ['submitted', 'acknowledged', 'in_progress', 'completed', 'cancelled'];

    // Human-readable type labels for dropdowns (dropdown-first per spec)
    public const TYPE_LABELS = [
        'lab_order'          => 'Lab Order',
        'referral'           => 'Referral',
        'home_care_visit'    => 'Home Care Visit',
        'transport_request'  => 'Transport Request',
        'equipment_dme'      => 'Equipment / DME',
        'pharmacy_change'    => 'Pharmacy Change',
        'assessment_request' => 'Assessment Request',
        'care_plan_update'   => 'Care Plan Update',
        'other'              => 'Other',
    ];

    // 72-hour enforcement window in hours
    public const DUE_WINDOW_HOURS = 72;

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'requesting_user_id',
        'requesting_department',
        'assigned_to_user_id',
        'assigned_department',
        'request_type',
        'description',
        'priority',
        'status',
        'submitted_at',
        'due_at',
        'completed_at',
        'completion_notes',
        'escalated',
        'escalation_reason',
        'escalated_at',
    ];

    protected $casts = [
        'submitted_at'  => 'datetime',
        'due_at'        => 'datetime',
        'completed_at'  => 'datetime',
        'escalated_at'  => 'datetime',
        'escalated'     => 'boolean',
    ];

    // ── Boot: enforce 72-hour due_at rule ─────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        // On create: auto-set due_at if not explicitly provided
        static::creating(function (Sdr $sdr) {
            if (empty($sdr->submitted_at)) {
                $sdr->submitted_at = now();
            }
            // Always enforce: due_at = submitted_at + 72h
            $sdr->due_at = $sdr->submitted_at->copy()->addHours(self::DUE_WINDOW_HOURS);
        });

        // On update: reject any attempt to push due_at beyond the 72h window
        static::updating(function (Sdr $sdr) {
            if ($sdr->isDirty('due_at')) {
                $maxDue = $sdr->submitted_at->copy()->addHours(self::DUE_WINDOW_HOURS);
                if ($sdr->due_at->gt($maxDue)) {
                    throw new \LogicException(
                        'SDR due_at cannot be set beyond ' . self::DUE_WINDOW_HOURS . ' hours of submitted_at. '
                        . 'Max allowed: ' . $maxDue->toIso8601String()
                    );
                }
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('due_at', '<', now());
    }

    public function scopeForDepartment(Builder $query, string $dept): Builder
    {
        return $query->where('assigned_department', $dept);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Returns hours remaining until due_at (negative = overdue). */
    public function hoursRemaining(): float
    {
        return now()->diffInHours($this->due_at, false);
    }

    /** True if past due_at and not completed/cancelled. */
    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled'], true)
            && $this->due_at->isPast();
    }

    /** Tailwind urgency color class based on hours remaining. */
    public function urgencyClasses(): string
    {
        if ($this->isOverdue()) {
            return 'text-red-700 bg-red-50';
        }

        $h = $this->hoursRemaining();

        return match (true) {
            $h <= 8  => 'text-orange-700 bg-orange-50',
            $h <= 24 => 'text-amber-700 bg-amber-50',
            default  => 'text-gray-600 bg-gray-50',
        };
    }

    /** Human-readable request type label (dropdown-first UX). */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->request_type]
            ?? ucwords(str_replace('_', ' ', $this->request_type));
    }

    /** Tailwind priority badge classes. */
    public function priorityClasses(): string
    {
        return match ($this->priority) {
            'emergent' => 'bg-red-50 text-red-700 ring-red-600/20',
            'urgent'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            default    => 'bg-gray-50 text-gray-600 ring-gray-500/10',
        };
    }

    /** Tailwind status badge classes. */
    public function statusClasses(): string
    {
        return match ($this->status) {
            'submitted'    => 'bg-blue-50 text-blue-700',
            'acknowledged' => 'bg-purple-50 text-purple-700',
            'in_progress'  => 'bg-amber-50 text-amber-700',
            'completed'    => 'bg-green-50 text-green-700',
            'cancelled'    => 'bg-gray-50 text-gray-500 line-through',
            default        => 'bg-gray-50 text-gray-600',
        };
    }
}
