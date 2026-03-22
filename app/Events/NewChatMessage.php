<?php

// ─── NewChatMessage Event ─────────────────────────────────────────────────────
// Broadcast via Reverb WebSocket when a message is sent to a channel.
// Frontend: Chat/Index.tsx subscribes to `private-chat.{channelId}` via Echo
// and appends the message in real-time without a page refresh.
//
// Channel: private — only users who are members of the channel can subscribe.
// Authorization is handled in routes/channels.php via ChatMembership lookup.
//
// NO PHI in broadcastWith(): the message_text itself is chat content (not
// EMR clinical data), but the payload is sent over the private encrypted
// Reverb channel so it is appropriately access-controlled.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ChatMessage $message) {}

    /** Broadcast on the private channel for this chat channel. */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.{$this->message->channel_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return $this->message->toApiArray();
    }
}
