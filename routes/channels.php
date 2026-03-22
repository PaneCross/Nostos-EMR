<?php

use App\Models\ChatMembership;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ─── Chat Channels ────────────────────────────────────────────────────────────
// Private channel per chat channel ID. Authorizes based on ChatMembership.
// Only users who are members of the channel may subscribe.
Broadcast::channel('chat.{channelId}', function ($user, int $channelId) {
    return ChatMembership::where('channel_id', $channelId)
        ->where('user_id', $user->id)
        ->exists();
});

// ─── Personal User Channel ────────────────────────────────────────────────────
// Used by ChatActivityEvent to push "you have new chat activity" notifications
// to a user's nav badge without subscribing to all their chat channels.
// Authorized only for the owning user.
Broadcast::channel('user.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
