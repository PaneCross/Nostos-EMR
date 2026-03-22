<?php

// ─── HpmsSubmission ───────────────────────────────────────────────────────────
// Tracks a CMS HPMS (Health Plan Management System) file submission.
//
// PACE organizations submit the following files to HPMS:
//   enrollment     — monthly, pipe-delimited, one record per newly enrolled participant
//   disenrollment  — monthly, pipe-delimited, one record per disenrolled participant
//   quality_data   — quarterly, fixed-width, hospitalization/immunization/fall rates
//   hos_m          — annual, HOS-M survey aggregate results
//
// file_content stores the generated flat-file content (newline-delimited records).
// File path never exposed in API — download goes through HpmsController.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HpmsSubmission extends Model
{
    use HasFactory;

    protected $table = 'emr_hpms_submissions';

    /** Human-readable labels for each HPMS submission type. */
    const SUBMISSION_TYPES = [
        'enrollment'    => 'Monthly Enrollment',
        'disenrollment' => 'Monthly Disenrollment',
        'quality_data'  => 'Quality Data Report',
        'hos_m'         => 'HOS-M Annual Survey',
    ];

    protected $fillable = [
        'tenant_id',
        'submission_type',
        'file_content',
        'record_count',
        'period_start',
        'period_end',
        'status',
        'submitted_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'submitted_at'  => 'datetime',
        'record_count'  => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($q, int $id)
    {
        return $q->where('tenant_id', $id);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Human-readable label for the submission type.
     */
    public function typeLabel(): string
    {
        return self::SUBMISSION_TYPES[$this->submission_type] ?? $this->submission_type;
    }
}
