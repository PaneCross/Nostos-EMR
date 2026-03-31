<?php

// ─── DayCenterAttendance ──────────────────────────────────────────────────────
// Records PACE participant attendance at the day center.
// One record per participant per site per day (unique constraint enforced).
// Statuses: present | absent | late | excused
// absent_reason required when status is absent or excused.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DayCenterAttendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_day_center_attendance';

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'site_id',
        'attendance_date',
        'status',
        'check_in_time',
        'check_out_time',
        'absent_reason',
        'notes',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];

    // ── Constants ─────────────────────────────────────────────────────────────

    const STATUSES = ['present', 'absent', 'late', 'excused'];

    const STATUS_LABELS = [
        'present' => 'Present',
        'absent'  => 'Absent',
        'late'    => 'Late',
        'excused' => 'Excused',
    ];

    const ABSENT_REASONS = [
        'hospitalized'   => 'Hospitalized',
        'illness'        => 'Illness / Not Feeling Well',
        'appointment'    => 'Outside Medical Appointment',
        'personal'       => 'Personal / Family Matter',
        'transportation' => 'Transportation Issue',
        'vacation'       => 'Vacation / Travel',
        'weather'        => 'Weather / Emergency',
        'other'          => 'Other',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope to a specific tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Scope to a specific date (carbon or string). */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('attendance_date', $date);
    }

    /** Scope to a specific site. Nullable: when siteId is null, no site filter is applied. */
    public function scopeForSite($query, ?int $siteId)
    {
        if ($siteId === null) {
            return $query;
        }
        return $query->where('site_id', $siteId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPresent(): bool
    {
        return $this->status === 'present' || $this->status === 'late';
    }

    public function absentReasonLabel(): string
    {
        return self::ABSENT_REASONS[$this->absent_reason] ?? $this->absent_reason ?? '';
    }
}
