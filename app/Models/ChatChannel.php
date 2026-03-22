<?php

// ─── ChatChannel Model ────────────────────────────────────────────────────────
// A conversation channel. Four types:
//   direct        — DM between exactly two users
//   department    — One per department; all dept users auto-joined at tenant setup
//   participant_idt — Per-participant IDT care-team channel; auto-created on enrollment
//   broadcast     — Org-wide; all users auto-joined at tenant setup
//
// Soft deletes are NOT used — channels are is_active=false when retired.
// Messages use soft deletes for HIPAA 6-year retention.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatChannel extends Model
{
    use HasFactory;

    protected $table = 'emr_chat_channels';

    // No updated_at — channels don't change after creation
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'channel_type',
        'name',
        'participant_id',
        'created_by_user_id',
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

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** All membership records for this channel. */
    public function memberships(): HasMany
    {
        return $this->hasMany(ChatMembership::class, 'channel_id');
    }

    /** Users who are members of this channel (pivot: emr_chat_memberships). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'emr_chat_memberships',
            'channel_id',
            'user_id'
        )->withPivot(['joined_at', 'last_read_at']);
    }

    /** All messages in this channel (including soft-deleted for audit). */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'channel_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Active channels only. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Channels the given user is a member of. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Display name for the channel from the perspective of a given user.
     * DM channels have no stored name — derive from the other participant.
     */
    public function displayName(User $viewer): string
    {
        if ($this->channel_type === 'direct') {
            $other = $this->members()
                ->where('shared_users.id', '!=', $viewer->id)
                ->first();
            return $other ? $other->fullName() : 'Direct Message';
        }

        return $this->name ?? 'Channel';
    }

    /** Unread message count for a given user. */
    public function unreadCountFor(User $user): int
    {
        $membership = $this->memberships()
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            return 0;
        }

        $query = $this->messages()->withoutTrashed();

        if ($membership->last_read_at) {
            $query->where('sent_at', '>', $membership->last_read_at);
        }

        return $query->count();
    }
}
