<?php

// ─── QapiProject Model ────────────────────────────────────────────────────────
// Represents a Quality Assessment and Performance Improvement (QAPI) quality
// improvement (QI) project.
//
// CMS Rule (42 CFR §460.136–§460.140): PACE organizations must maintain at least
// 2 active QI projects at any time of the year as part of the QAPI program.
// This model tracks the project lifecycle, interventions, and outcome measurements.
//
// Lifecycle: planning → active → remeasuring → completed
//            Any status → suspended (pause without closing)
//
// Routes: GET/POST /qapi/projects, GET/PATCH /qapi/projects/{id},
//         POST /qapi/projects/{id}/remeasure
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QapiProject extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_qapi_projects';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** All valid status values in lifecycle order. */
    public const STATUSES = ['planning', 'active', 'remeasuring', 'completed', 'suspended'];

    /** Human-readable labels for statuses. */
    public const STATUS_LABELS = [
        'planning'    => 'Planning',
        'active'      => 'Active',
        'remeasuring' => 'Remeasuring',
        'completed'   => 'Completed',
        'suspended'   => 'Suspended',
    ];

    /** Valid improvement domains. */
    public const DOMAINS = [
        'clinical_outcomes',
        'safety',
        'access',
        'satisfaction',
        'efficiency',
    ];

    /** Human-readable labels for domains. */
    public const DOMAIN_LABELS = [
        'clinical_outcomes' => 'Clinical Outcomes',
        'safety'            => 'Safety',
        'access'            => 'Access to Care',
        'satisfaction'      => 'Member/Family Satisfaction',
        'efficiency'        => 'Efficiency',
    ];

    /** CMS minimum active project requirement. */
    public const MIN_ACTIVE_PROJECTS = 2;

    // ── Fillable + Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'aim_statement',
        'domain',
        'status',
        'start_date',
        'target_completion_date',
        'actual_completion_date',
        'baseline_metric',
        'target_metric',
        'current_metric',
        'project_lead_user_id',
        'team_member_ids',
        'interventions',
        'findings',
        'created_by_user_id',
    ];

    protected $casts = [
        'start_date'              => 'date',
        'target_completion_date'  => 'date',
        'actual_completion_date'  => 'date',
        'team_member_ids'         => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projectLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_lead_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True when this project counts toward the CMS active project minimum. */
    public function isActive(): bool
    {
        return in_array($this->status, ['planning', 'active', 'remeasuring'], true);
    }

    /** True when the project is completed. */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** True when the project is suspended. */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Human-readable label for the current status. */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Human-readable label for the domain. */
    public function domainLabel(): string
    {
        return self::DOMAIN_LABELS[$this->domain] ?? $this->domain;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only projects that count toward the CMS 2-project minimum. */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['planning', 'active', 'remeasuring']);
    }

    /** Filter by domain. */
    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }
}
