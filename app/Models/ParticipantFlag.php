<?php

namespace App\Models;

use App\Events\ParticipantFlagUpdated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParticipantFlag extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_participant_flags';

    protected $fillable = [
        'participant_id', 'tenant_id', 'flag_type',
        'description', 'severity', 'is_active',
        'created_by_user_id', 'resolved_by_user_id', 'resolved_at',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'resolved_at' => 'datetime',
    ];

    // Mobility-relevant flags that should be synced to transport system
    public const TRANSPORT_FLAGS = ['wheelchair', 'stretcher', 'oxygen', 'behavioral'];

    protected static function booted(): void
    {
        $dispatch = fn (ParticipantFlag $flag) => event(new ParticipantFlagUpdated($flag));

        static::created($dispatch);
        static::updated($dispatch);
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    public function isTransportRelevant(): bool
    {
        return in_array($this->flag_type, self::TRANSPORT_FLAGS, true);
    }

    public function resolve(User $resolvedBy): void
    {
        $this->update([
            'is_active'          => false,
            'resolved_by_user_id'=> $resolvedBy->id,
            'resolved_at'        => now(),
        ]);
    }

    public function label(): string
    {
        return match ($this->flag_type) {
            'wheelchair'              => 'Wheelchair',
            'stretcher'               => 'Stretcher',
            'oxygen'                  => 'Oxygen',
            'behavioral'              => 'Behavioral',
            'fall_risk'               => 'Fall Risk',
            'wandering_risk'          => 'Wandering Risk',
            'isolation'               => 'Isolation',
            'dnr'                     => 'DNR',
            'weight_bearing_restriction' => 'Weight Bearing',
            'dietary_restriction'     => 'Dietary',
            'elopement_risk'          => 'Elopement Risk',
            'hospice'                 => 'Hospice',
            default                   => 'Other',
        };
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            'low'      => 'blue',
            'medium'   => 'yellow',
            'high'     => 'orange',
            'critical' => 'red',
            default    => 'gray',
        };
    }
}
