<?php

// ─── ClinicalNote Model ──────────────────────────────────────────────────────────
// Represents a clinical documentation entry (SOAP, nursing progress, therapy, etc.).
// Signed notes are immutable — any correction requires an addendum (new note with
// parent_note_id pointing here). Amendments set status = 'amended' on this note.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_clinical_notes';

    // ── Valid note types ──────────────────────────────────────────────────────
    public const NOTE_TYPES = [
        'soap', 'progress_nursing', 'therapy_pt', 'therapy_ot', 'therapy_st',
        'social_work', 'behavioral_health', 'dietary', 'home_visit',
        'telehealth', 'idt_summary', 'incident', 'addendum',
    ];

    // ── Note types that use the SOAP subjective/objective/assessment/plan fields
    public const SOAP_NOTE_TYPES = ['soap'];

    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_AMENDED = 'amended';

    protected $fillable = [
        'participant_id', 'tenant_id', 'site_id',
        'note_type', 'authored_by_user_id', 'department',
        'status', 'visit_type', 'visit_date', 'visit_time',
        'subjective', 'objective', 'assessment', 'plan',
        'content',
        'signed_at', 'signed_by_user_id',
        'parent_note_id',
        'is_late_entry', 'late_entry_reason',
    ];

    protected $casts = [
        'content'      => 'array',
        'signed_at'    => 'datetime',
        'visit_date'   => 'date',
        'is_late_entry' => 'boolean',
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    /** The note this entry annotates (null if this is not an addendum). */
    public function parentNote(): BelongsTo
    {
        return $this->belongsTo(ClinicalNote::class, 'parent_note_id');
    }

    /** Addenda and amendments written against this note. */
    public function addenda(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'parent_note_id')->latest();
    }

    // ── Query Scopes ─────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUnsigned($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeForDepartment($query, string $department)
    {
        return $query->where('department', $department);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSoapNote(): bool
    {
        return in_array($this->note_type, self::SOAP_NOTE_TYPES, true);
    }

    /**
     * Whether this note may be edited by the given user.
     * Only draft notes may be edited, and only by their author.
     */
    public function canEdit(User $user): bool
    {
        return $this->isDraft() && $this->authored_by_user_id === $user->id;
    }

    /**
     * Sign this note. Sets status, timestamps, and signing user.
     * After signing, the note is immutable — use addendum() to annotate.
     */
    public function sign(User $user): void
    {
        $this->update([
            'status'             => self::STATUS_SIGNED,
            'signed_at'          => now(),
            'signed_by_user_id'  => $user->id,
        ]);
    }

    /**
     * Human-readable label for the note type.
     */
    public function noteTypeLabel(): string
    {
        return match ($this->note_type) {
            'soap'               => 'Primary Care SOAP',
            'progress_nursing'   => 'Nursing Progress',
            'therapy_pt'         => 'PT Therapy Session',
            'therapy_ot'         => 'OT Therapy Session',
            'therapy_st'         => 'ST Therapy Session',
            'social_work'        => 'Social Work',
            'behavioral_health'  => 'Behavioral Health',
            'dietary'            => 'Dietary / Nutrition',
            'home_visit'         => 'Home Visit',
            'telehealth'         => 'Telehealth',
            'idt_summary'        => 'IDT Meeting Summary',
            'incident'           => 'Incident Report',
            'addendum'           => 'Addendum',
            default              => ucwords(str_replace('_', ' ', $this->note_type)),
        };
    }
}
