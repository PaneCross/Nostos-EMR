<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'shared_users';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'first_name',
        'last_name',
        'email',
        'department',
        'role',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'locked_until',
        'provisioned_by_user_id',
        'provisioned_at',
        'notification_preferences',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'is_active'                  => 'boolean',
        'last_login_at'              => 'datetime',
        'locked_until'               => 'datetime',
        'provisioned_at'             => 'datetime',
        'failed_login_attempts'      => 'integer',
        'notification_preferences'   => 'array',
    ];

    // Passwordless — no password column needed
    public function getAuthPassword(): string
    {
        return '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class, 'user_id');
    }

    public function provisionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provisioned_by_user_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function departmentLabel(): string
    {
        return match ($this->department) {
            'primary_care'      => 'Primary Care / Nursing',
            'therapies'         => 'Therapies (PT/OT/ST)',
            'social_work'       => 'Social Work',
            'behavioral_health' => 'Behavioral Health',
            'dietary'           => 'Dietary / Nutrition',
            'activities'        => 'Activities / Recreation',
            'home_care'         => 'Home Care',
            'transportation'    => 'Transportation',
            'pharmacy'          => 'Pharmacy',
            'idt'               => 'IDT / Care Coordination',
            'enrollment'        => 'Enrollment / Intake',
            'finance'           => 'Finance / Billing',
            'qa_compliance'     => 'QA / Compliance',
            'it_admin'          => 'IT / Administration',
            'executive'         => 'Executive / Leadership',
            'super_admin'       => 'Nostos Super Admin',
            default             => ucfirst($this->department),
        };
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Executive users have cross-site, read-only access within their tenant. Phase 10B. */
    public function isExecutive(): bool
    {
        return $this->department === 'executive';
    }

    /** Nostos staff with cross-tenant platform access. Distinct from role='super_admin'. Phase 10B. */
    public function isDeptSuperAdmin(): bool
    {
        return $this->department === 'super_admin';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function incrementFailedAttempts(): void
    {
        $this->increment('failed_login_attempts');

        if ($this->failed_login_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
        }
    }

    public function resetFailedAttempts(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);
    }

    // ─── Notification Preferences ─────────────────────────────────────────────

    /**
     * Return the delivery preference for a given notification type key.
     * Falls back to 'in_app_only' if no preference is configured.
     *
     * @param  string  $key  e.g. 'alert_critical', 'sdr_overdue', 'new_message'
     * @return string  'in_app_only' | 'email_immediate' | 'email_digest' | 'off'
     */
    public function notificationPreference(string $key): string
    {
        $prefs = $this->notification_preferences ?? [];
        return $prefs[$key] ?? 'in_app_only';
    }

    // ─── Chat Relationships ───────────────────────────────────────────────────

    public function chatMemberships(): HasMany
    {
        return $this->hasMany(ChatMembership::class, 'user_id');
    }

    public function chatChannels(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            ChatChannel::class,
            'emr_chat_memberships',
            'user_id',
            'channel_id'
        )->withPivot(['joined_at', 'last_read_at']);
    }
}
