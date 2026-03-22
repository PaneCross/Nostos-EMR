<?php

// ─── Location Model ────────────────────────────────────────────────────────────
// Physical or service locations used for appointment scheduling in a PACE program.
//
// Location types:
//   pace_center        — The main PACE day center (where most in-center visits happen)
//   acs_location       — Adult Care Setting (alternative care site)
//   dialysis           — Contracted dialysis facility
//   specialist         — Specialist office (contracted or community)
//   hospital           — Hospital / ED / inpatient
//   pharmacy           — Contracted pharmacy
//   lab                — Laboratory (Quest, LabCorp, hospital lab)
//   day_program        — Adult day program (non-PACE)
//   other_external     — Any other external location
//
// Managed by: Transportation Team (create/edit/delete).
// Used by: Appointment scheduling (location_id FK on emr_appointments).
//
// Soft deletes preserve history — archived locations still appear on past appointments.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_locations';

    // ── Location type constants ────────────────────────────────────────────────
    public const LOCATION_TYPES = [
        'pace_center',
        'acs_location',
        'dialysis',
        'specialist',
        'hospital',
        'pharmacy',
        'lab',
        'day_program',
        'other_external',
    ];

    // ── Human-readable labels for each location type ───────────────────────────
    public const TYPE_LABELS = [
        'pace_center'    => 'PACE Center',
        'acs_location'   => 'ACS Location',
        'dialysis'       => 'Dialysis Center',
        'specialist'     => 'Specialist Office',
        'hospital'       => 'Hospital',
        'pharmacy'       => 'Pharmacy',
        'lab'            => 'Laboratory',
        'day_program'    => 'Day Program',
        'other_external' => 'Other External',
    ];

    protected $fillable = [
        'tenant_id',
        'location_type',
        'name',
        'label',
        'street',
        'unit',
        'city',
        'state',
        'zip',
        'phone',
        'contact_name',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Appointments scheduled at this location. */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict to the current tenant (multi-tenancy boundary). */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only locations that are currently active (is_active = true). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Filter to a specific location_type. */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('location_type', $type);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Human-readable label for the location type (e.g. 'dialysis' → 'Dialysis Center').
     */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->location_type] ?? ucwords(str_replace('_', ' ', $this->location_type));
    }

    /**
     * Formatted full mailing address.
     * Returns the label/name as fallback when street is not set (e.g. virtual locations).
     */
    public function fullAddress(): string
    {
        if (! $this->street) {
            return $this->label ?? $this->name;
        }

        $lines = array_filter([
            trim($this->street . ($this->unit ? ', ' . $this->unit : '')),
            trim($this->city . ', ' . $this->state . ' ' . $this->zip),
        ]);

        return implode("\n", $lines);
    }
}
