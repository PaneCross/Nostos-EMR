<?php

// ─── ChatMembership Model ─────────────────────────────────────────────────────
// Links a user to a channel. Tracks when they last read the channel
// so the UI can show unread badges.
//
// UNIQUE: (channel_id, user_id) — one membership record per user per channel.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMembership extends Model
{
    use HasFactory;

    protected $table = 'emr_chat_memberships';

    // Use joined_at / last_read_at, not created_at/updated_at
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = [
        'channel_id',
        'user_id',
        'joined_at',
        'last_read_at',
    ];

    protected $casts = [
        'joined_at'    => 'datetime',
        'last_read_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
