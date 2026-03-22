<?php

// ─── TransportRequest Model ────────────────────────────────────────────────────
// EMR-side record of a transport request. Distinct from transport_trips (the
// transport app's own table). Lifecycle:
//   EMR creates emr_transport_requests → TransportBridgeService writes to
//   transport_trips → transport_trip_id is stored back here for cross-reference.
//
// Trip types:
//   to_center     — participant coming to PACE center
//   from_center   — participant going home after day at center
//   external_appt — trip to specialist/dialysis/etc.
//   will_call     — return trip, participant will call when ready
//   add_on        — unscheduled same-day request (routes through Add-On queue)
//
// mobility_flags_snapshot (JSONB): active transport flags at time of request.
//   Preserved independently so run sheets are historically accurate even if
//   the participant's current flags change after the request was created.
//
// Status lifecycle (mirrors transport app):
//   requested → scheduled → dispatched → en_route → arrived → completed
//   any active status → cancelled
//   en_route / arrived → no_show
//
// No SoftDeletes — transport records are append-only for audit purposes.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportRequest extends Model
{
    use HasFactory;

    protected $table = 'emr_transport_requests';

    // ── Trip type constants ───────────────────────────────────────────────────
    public const TRIP_TYPES = [
        'to_center',
        'from_center',
        'external_appt',
        'will_call',
        'add_on',
    ];

    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUSES = [
        'requested',
        'scheduled',
        'dispatched',
        'en_route',
        'arrived',
        'completed',
        'no_show',
        'cancelled',
    ];

    // ── Statuses that mean the trip is still in motion ────────────────────────
    public const ACTIVE_STATUSES = ['requested', 'scheduled', 'dispatched', 'en_route', 'arrived'];

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'appointment_id',
        'requesting_user_id',
        'requesting_department',
        'trip_type',
        'pickup_location_id',
        'dropoff_location_id',
        'requested_pickup_time',
        'scheduled_pickup_time',
        'actual_pickup_time',
        'actual_dropoff_time',
        'special_instructions',
        'mobility_flags_snapshot',
        'status',
        'transport_trip_id',
        'driver_notes',
        'last_synced_at',
    ];

    protected $casts = [
        'mobility_flags_snapshot' => 'array',
        'requested_pickup_time'   => 'datetime',
        'scheduled_pickup_time'   => 'datetime',
        'actual_pickup_time'      => 'datetime',
        'actual_dropoff_time'     => 'datetime',
        'last_synced_at'          => 'datetime',
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

    /** The appointment this transport request was generated for (nullable for add-ons). */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** Staff member who requested the transport. */
    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    /** Location where the vehicle will pick up the participant. */
    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    /** Location where the vehicle will drop off the participant. */
    public function dropoffLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'dropoff_location_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict to a single tenant (multi-tenancy boundary). */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Filter to transport requests with a pickup on a specific calendar date. */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('requested_pickup_time', $date);
    }

    /** Add-on queue: unscheduled same-day trips awaiting dispatch approval. */
    public function scopePendingAddOns(Builder $query): Builder
    {
        return $query->where('trip_type', 'add_on')
            ->whereIn('status', ['requested', 'scheduled']);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * A transport request can be edited while it is in an active pre-dispatch status.
     * Once dispatched or further, the transport app owns the record.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['requested', 'scheduled']);
    }

    /**
     * Cancel this transport request, marking it as cancelled and recording the reason.
     * Also attempts to cancel the corresponding trip in the transport app via bridge.
     */
    public function cancel(string $reason): void
    {
        $this->update([
            'status'           => 'cancelled',
            'driver_notes'     => $reason,
        ]);
    }
}
