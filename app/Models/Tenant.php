<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $table = 'shared_tenants';

    protected $fillable = [
        'name',
        'slug',
        'transport_mode',
        'cms_contract_id',
        'state',
        'timezone',
        'auto_logout_minutes',
        'is_active',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'auto_logout_minutes'  => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'tenant_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function isBrokerMode(): bool
    {
        return $this->transport_mode === 'broker';
    }
}
