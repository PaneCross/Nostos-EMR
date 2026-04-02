<?php

// ─── LabResultComponent ───────────────────────────────────────────────────────
// Individual analyte result within a lab panel. Maps to HL7 OBX segments.
//
// Examples within a CBC panel:
//   component_name: "Hemoglobin", value: "8.2", unit: "g/dL",
//   reference_range: "12.0-16.0", abnormal_flag: "low"
//
// No SoftDeletes: components are immutable records of lab analyte values.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResultComponent extends Model
{
    use HasFactory;

    protected $table = 'emr_lab_result_components';

    // ── Constants ─────────────────────────────────────────────────────────────

    public const ABNORMAL_FLAGS = [
        'normal', 'low', 'high', 'critical_low', 'critical_high', 'abnormal',
    ];

    /** Flags that require urgent clinical attention. */
    public const CRITICAL_FLAGS = ['critical_low', 'critical_high'];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'lab_result_id',
        'component_name', 'component_code',
        'value', 'unit', 'reference_range',
        'abnormal_flag',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function labResult(): BelongsTo
    {
        return $this->belongsTo(LabResult::class);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isAbnormal(): bool
    {
        return $this->abnormal_flag !== null && $this->abnormal_flag !== 'normal';
    }

    public function isCritical(): bool
    {
        return in_array($this->abnormal_flag, self::CRITICAL_FLAGS, true);
    }

    /** API-safe array. */
    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'component_name'  => $this->component_name,
            'component_code'  => $this->component_code,
            'value'           => $this->value,
            'unit'            => $this->unit,
            'reference_range' => $this->reference_range,
            'abnormal_flag'   => $this->abnormal_flag,
            'is_abnormal'     => $this->isAbnormal(),
            'is_critical'     => $this->isCritical(),
        ];
    }
}
