<?php

// ─── ChatMessage Model ────────────────────────────────────────────────────────
// A single message in a chat channel.
//
// HIPAA 6-year retention: messages are NEVER hard-deleted.
// Soft-deleted messages render as "This message was deleted" in the UI.
// The message_text is preserved in the DB for audit/legal purposes.
//
// urgent priority messages show a red left border + "URGENT" badge.
//
// edited_at is set when a message is edited (future feature). For now,
// the column exists for the schema but editing is not exposed via API.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_chat_messages';

    // No updated_at — use edited_at for tracking message edits
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = [
        'channel_id',
        'sender_user_id',
        'message_text',
        'priority',
        'sent_at',
        'edited_at',
    ];

    protected $casts = [
        'sent_at'   => 'datetime',
        'edited_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** True when this message has been soft-deleted. */
    public function isDeleted(): bool
    {
        return $this->trashed();
    }

    /** True when this message was sent with urgent priority. */
    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    /**
     * API-safe representation. Deleted messages expose only the placeholder
     * text — never the original message_text — preserving HIPAA boundaries
     * while showing the conversation thread structure.
     */
    public function toApiArray(): array
    {
        $isDeleted = $this->isDeleted();

        return [
            'id'             => $this->id,
            'channel_id'     => $this->channel_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_name'    => $this->sender?->fullName(),
            'sender_initials'=> $this->sender
                ? strtoupper(substr($this->sender->first_name, 0, 1) . substr($this->sender->last_name, 0, 1))
                : '??',
            'message_text'   => $isDeleted ? null : $this->message_text,
            'is_deleted'     => $isDeleted,
            'priority'       => $this->priority,
            'sent_at'        => $this->sent_at?->toIso8601String(),
            'edited_at'      => $this->edited_at?->toIso8601String(),
        ];
    }
}
