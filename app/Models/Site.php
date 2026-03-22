<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// NOTE: Participant is referenced below — no import needed (same namespace)

class Site extends Model
{
    use HasFactory;

    protected $table = 'shared_sites';

    protected $fillable = [
        'tenant_id',
        'name',
        'mrn_prefix',
        'address',
        'city',
        'state',
        'zip',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'site_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'site_id');
    }
}
