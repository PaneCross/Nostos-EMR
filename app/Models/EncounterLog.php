<?php

// ─── EncounterLog ─────────────────────────────────────────────────────────────
// Records a care encounter (service delivery event) for billing and compliance.
//
// Used by the Finance team to track billable encounters for capitation
// reconciliation and CMS reporting. Append-only (no SoftDeletes) to maintain
// a complete, tamper-evident encounter history.
//
// Service types map to CMS procedure codes for PACE encounter reporting.
//
// Phase 9B: 837P billing fields added (diagnosis_codes, NPI fields, POS, units,
// charge, claim type, submission tracking). isSubmittable() validates required
// fields before an encounter can be included in an EDI 837P batch.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncounterLog extends Model
{
    use HasFactory;

    protected $table = 'emr_encounter_log';

    // Append-only: no updated_at (encounters are immutable records)
    public const UPDATED_AT = null;

    /** PACE service types mapped to human-readable labels. */
    public const SERVICE_TYPES = [
        'primary_care'      => 'Primary Care Visit',
        'specialist'        => 'Specialist Consult',
        'therapy'           => 'Therapy Session',
        'social_work'       => 'Social Work',
        'behavioral_health' => 'Behavioral Health',
        'dietary'           => 'Dietary Counseling',
        'home_care'         => 'Home Care Visit',
        'day_center'        => 'Day Center Attendance',
        'transportation'    => 'Medical Transport',
        'pharmacy'          => 'Pharmacy Services',
        'activities'        => 'Activities / Recreation',
        'other'             => 'Other',
    ];

    /** Valid claim types for 837P submission routing. */
    public const CLAIM_TYPES = [
        'internal_capitated', // PACE center all-inclusive service
        'external_claim',     // External provider — generates EDR
        'chart_review_crr',   // Chart review record (CRR)
    ];

    /** Encounter submission lifecycle statuses. */
    public const SUBMISSION_STATUSES = [
        'pending',   // Not yet included in any EDI batch
        'submitted', // Included in an EDI batch, awaiting 277CA
        'accepted',  // CMS accepted the encounter record
        'rejected',  // CMS rejected — see rejection_reason
        'void',      // Voided after submission
    ];

    /** CMS Place of Service codes relevant to PACE. */
    public const PLACE_OF_SERVICE_CODES = [
        '02' => 'Telehealth',
        '11' => 'Office',
        '12' => 'Home',
        '49' => 'Independent Clinic',
        '65' => 'PACE Center',
    ];

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'service_date',
        'service_type',
        'procedure_code',
        'provider_user_id',
        'notes',
        'created_by_user_id',
        // Phase 9B: 837P billing fields
        'billing_provider_npi',
        'rendering_provider_npi',
        'service_facility_npi',
        'diagnosis_codes',
        'procedure_modifier',
        'place_of_service_code',
        'units',
        'charge_amount',
        'claim_type',
        'submission_status',
        'submitted_at',
        'edi_batch_id',
        'cms_acknowledgement_status',
        'rejection_reason',
    ];

    protected $casts = [
        'service_date'   => 'date',
        'diagnosis_codes'=> 'array',
        'units'          => 'decimal:2',
        'charge_amount'  => 'decimal:2',
        'submitted_at'   => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function ediBatch(): BelongsTo
    {
        return $this->belongsTo(EdiBatch::class, 'edi_batch_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForParticipant($query, int $participantId)
    {
        return $query->where('participant_id', $participantId);
    }

    /**
     * Scope to encounters awaiting first submission (status = pending).
     */
    public function scopePendingSubmission($query)
    {
        return $query->where('submission_status', 'pending');
    }

    /**
     * Scope to encounters eligible for batching (pending or rejected).
     */
    public function scopeForSubmission($query)
    {
        return $query->whereIn('submission_status', ['pending', 'rejected']);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Whether this encounter has all required fields for 837P submission.
     * Required: at least one diagnosis code, a procedure code, and a billing NPI.
     */
    public function isSubmittable(): bool
    {
        return !empty($this->diagnosis_codes)
            && !empty($this->procedure_code)
            && !empty($this->billing_provider_npi);
    }

    /**
     * Whether this encounter has any diagnosis codes populated.
     */
    public function hasDiagnoses(): bool
    {
        return !empty($this->diagnosis_codes);
    }
}
