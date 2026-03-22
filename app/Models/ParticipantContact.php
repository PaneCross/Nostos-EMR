<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParticipantContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_participant_contacts';

    protected $fillable = [
        'participant_id', 'contact_type',
        'first_name', 'last_name', 'relationship',
        'phone_primary', 'phone_secondary', 'email',
        'is_legal_representative', 'is_emergency_contact',
        'priority_order', 'notes',
    ];

    protected $casts = [
        'is_legal_representative' => 'boolean',
        'is_emergency_contact'    => 'boolean',
        'priority_order'          => 'integer',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
