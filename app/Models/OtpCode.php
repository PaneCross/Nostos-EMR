<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    protected $table = 'shared_otp_codes';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'used_at',
        'ip_address',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
        'attempts'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function verifyCode(string $plainCode): bool
    {
        return Hash::check($plainCode, $this->code_hash);
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
