<?php

// ─── ChatActivityEvent ────────────────────────────────────────────────────────
// Broadcast on a user's personal private channel when they receive a new chat
// message in any channel they belong to. Used to update the unread badge in
// the AppShell nav without polling.
//
// Channel: private-user.{userId}  (one per user)
// Payload: { channel_id } — no PHI, just enough for the frontend to know
//          which channel has new activity so it can refresh its count.
//
// Dispatched from: ChatController::send() for each channel member.
// Frontend: AppShell.tsx subscribes on mount and re-fetches /chat/unread-count.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatActivityEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $channelId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.activity';
    }

    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId];
    }
}
