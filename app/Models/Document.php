<?php

// ─── Document ────────────────────────────────────────────────────────────────
// Participant-level document record. Stores metadata only — file bytes live
// on disk at storage/app/{file_path}.
//
// Soft-delete enforced: HIPAA prohibits permanent destruction of participant
// documents. Use ->delete() to soft-delete; hard restores require super-admin.
//
// VALID_CATEGORIES drives the document_category DB value and the UI filter chips.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_documents';

    // Map standard Laravel timestamps to document-specific column names.
    // uploaded_at replaces created_at; no updated_at (documents are immutable).
    const CREATED_AT = 'uploaded_at';
    const UPDATED_AT = null;

    // ── Document category constants ───────────────────────────────────────────
    // Used in DB CHECK constraint and frontend filter chips.
    const VALID_CATEGORIES = [
        'consent',
        'care_plan',
        'referral',
        'lab_report',
        'imaging',
        'insurance',
        'legal',
        'clinical_note',
        'assessment',
        'other',
    ];

    const CATEGORY_LABELS = [
        'consent'       => 'Consent Forms',
        'care_plan'     => 'Care Plans',
        'referral'      => 'Referrals',
        'lab_report'    => 'Lab Reports',
        'imaging'       => 'Imaging',
        'insurance'     => 'Insurance',
        'legal'         => 'Legal',
        'clinical_note' => 'Clinical Notes',
        'assessment'    => 'Assessments',
        'other'         => 'Other',
    ];

    // ── Accepted MIME types / extensions ─────────────────────────────────────
    const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

    /** Max upload size in bytes (20 MB) */
    const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'site_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'description',
        'document_category',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at'     => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant (always required for multi-tenant safety). */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Filter by category slug. */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('document_category', $category);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable file size (e.g. "2.3 MB"). */
    public function formattedSize(): string
    {
        $bytes = $this->file_size_bytes;
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1_048_576, 1) . ' MB';
    }

    /** Serialised shape returned to the React DocumentsTab. */
    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'file_name'         => $this->file_name,
            'file_type'         => $this->file_type,
            'file_size'         => $this->formattedSize(),
            'file_size_bytes'   => $this->file_size_bytes,
            'description'       => $this->description,
            'document_category' => $this->document_category,
            'category_label'    => self::CATEGORY_LABELS[$this->document_category] ?? 'Other',
            'uploaded_by'       => $this->uploader
                ? $this->uploader->first_name . ' ' . $this->uploader->last_name
                : 'Unknown',
            'uploaded_at'       => $this->uploaded_at?->toDateTimeString(),
        ];
    }
}
