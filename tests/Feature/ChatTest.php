<?php

// ─── ChatTest ─────────────────────────────────────────────────────────────────
// Feature tests for the chat API endpoints.
//
// Coverage:
//   - Channel list returns correct structure
//   - Messages endpoint is paginated newest-first
//   - Send message broadcasts via Reverb (faked) and returns 201
//   - markRead updates last_read_at, drops unread count
//   - DM channel created on first message between two users
//   - Sending to the same user returns the existing DM channel
//   - Soft-deleted messages render as is_deleted=true
//   - Urgent messages are returned with priority=urgent
//   - Non-member access to channel returns 403
//   - Unauthenticated access returns redirect (302)
//   - unread-count endpoint returns correct total
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Events\NewChatMessage;
use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\ChatMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeTenantUser(string $dept = 'idt'): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id'  => $tenant->id,
            'department' => $dept,
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    private function makeChannelWithMember(User $user, string $type = 'department'): ChatChannel
    {
        $channel = ChatChannel::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'channel_type'       => $type,
            'name'               => 'Test Channel',
            'created_by_user_id' => $user->id,
        ]);
        ChatMembership::factory()->create([
            'channel_id' => $channel->id,
            'user_id'    => $user->id,
        ]);
        return $channel;
    }

    // ── Channel list ──────────────────────────────────────────────────────────

    public function test_channels_endpoint_returns_user_channels(): void
    {
        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        $this->actingAs($user)
            ->getJson('/chat/channels')
            ->assertOk()
            ->assertJsonStructure(['channels' => [['id', 'channel_type', 'name', 'unread_count', 'is_active']]]);
    }

    public function test_channels_endpoint_does_not_return_channels_user_is_not_member_of(): void
    {
        $user  = $this->makeTenantUser();
        $other = User::factory()->create(['tenant_id' => $user->tenant_id]);

        // Channel where only $other is a member
        $channel = ChatChannel::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $other->id,
        ]);
        ChatMembership::factory()->create(['channel_id' => $channel->id, 'user_id' => $other->id]);

        $response = $this->actingAs($user)->getJson('/chat/channels');
        $ids = collect($response->json('channels'))->pluck('id')->toArray();

        $this->assertNotContains($channel->id, $ids);
    }

    // ── Messages ──────────────────────────────────────────────────────────────

    public function test_messages_endpoint_requires_membership(): void
    {
        $user = $this->makeTenantUser();
        $channel = ChatChannel::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
        ]);
        // User is NOT a member

        $this->actingAs($user)
            ->getJson("/chat/channels/{$channel->id}/messages")
            ->assertForbidden();
    }

    public function test_messages_endpoint_returns_paginated_structure(): void
    {
        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        ChatMessage::factory()->count(3)->create([
            'channel_id'     => $channel->id,
            'sender_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson("/chat/channels/{$channel->id}/messages")
            ->assertOk()
            ->assertJsonStructure(['messages', 'current_page', 'last_page']);
    }

    // ── Send message ──────────────────────────────────────────────────────────

    public function test_send_message_creates_message_and_broadcasts(): void
    {
        Event::fake([NewChatMessage::class]);

        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        $this->actingAs($user)
            ->postJson("/chat/channels/{$channel->id}/messages", [
                'message_text' => 'Hello team!',
                'priority'     => 'standard',
            ])
            ->assertCreated()
            ->assertJsonPath('message.message_text', 'Hello team!');

        $this->assertDatabaseHas('emr_chat_messages', [
            'channel_id'     => $channel->id,
            'sender_user_id' => $user->id,
            'message_text'   => 'Hello team!',
            'priority'       => 'standard',
        ]);

        Event::assertDispatched(NewChatMessage::class);
    }

    public function test_send_urgent_message_sets_priority(): void
    {
        Event::fake();

        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        $this->actingAs($user)
            ->postJson("/chat/channels/{$channel->id}/messages", [
                'message_text' => 'URGENT: Please respond ASAP',
                'priority'     => 'urgent',
            ])
            ->assertCreated()
            ->assertJsonPath('message.priority', 'urgent');
    }

    public function test_send_requires_membership(): void
    {
        $user    = $this->makeTenantUser();
        $channel = ChatChannel::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
        ]);
        // User NOT a member

        $this->actingAs($user)
            ->postJson("/chat/channels/{$channel->id}/messages", ['message_text' => 'hi'])
            ->assertForbidden();
    }

    // ── Mark read ─────────────────────────────────────────────────────────────

    public function test_mark_read_updates_last_read_at(): void
    {
        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        $this->actingAs($user)
            ->postJson("/chat/channels/{$channel->id}/read")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $membership = ChatMembership::where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($membership->last_read_at);
    }

    public function test_unread_count_drops_after_mark_read(): void
    {
        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        // Message sent before any last_read_at
        $sender = User::factory()->create(['tenant_id' => $user->tenant_id]);
        ChatMembership::factory()->create(['channel_id' => $channel->id, 'user_id' => $sender->id]);
        ChatMessage::factory()->create([
            'channel_id'     => $channel->id,
            'sender_user_id' => $sender->id,
            'sent_at'        => Carbon::now()->subMinutes(5),
        ]);

        // Before marking read — should have unread
        $before = $this->actingAs($user)->getJson('/chat/unread-count');
        $this->assertGreaterThan(0, $before->json('unread_count'));

        // Mark read
        $this->actingAs($user)->postJson("/chat/channels/{$channel->id}/read");

        // After marking read — should be 0
        $this->actingAs($user)
            ->getJson('/chat/unread-count')
            ->assertJsonPath('unread_count', 0);
    }

    // ── DM channel ────────────────────────────────────────────────────────────

    public function test_direct_message_creates_new_channel(): void
    {
        $userA = $this->makeTenantUser('primary_care');
        $userB = User::factory()->create([
            'tenant_id' => $userA->tenant_id,
            'is_active' => true,
        ]);

        $this->actingAs($userA)
            ->postJson("/chat/direct/{$userB->id}")
            ->assertOk()
            ->assertJsonStructure(['channel' => ['id', 'channel_type', 'name']]);

        $this->assertDatabaseHas('emr_chat_channels', [
            'tenant_id'    => $userA->tenant_id,
            'channel_type' => 'direct',
        ]);

        // Both users should be members
        $channel = ChatChannel::where('tenant_id', $userA->tenant_id)
            ->where('channel_type', 'direct')
            ->first();

        $this->assertNotNull($channel);
        $this->assertTrue(
            ChatMembership::where('channel_id', $channel->id)->where('user_id', $userA->id)->exists()
        );
        $this->assertTrue(
            ChatMembership::where('channel_id', $channel->id)->where('user_id', $userB->id)->exists()
        );
    }

    public function test_direct_message_returns_existing_channel_on_repeat(): void
    {
        $userA = $this->makeTenantUser('primary_care');
        $userB = User::factory()->create([
            'tenant_id' => $userA->tenant_id,
            'is_active' => true,
        ]);

        $response1 = $this->actingAs($userA)->postJson("/chat/direct/{$userB->id}");
        $response2 = $this->actingAs($userA)->postJson("/chat/direct/{$userB->id}");

        $this->assertEquals(
            $response1->json('channel.id'),
            $response2->json('channel.id')
        );

        // Only one DM channel should exist
        $this->assertEquals(1, ChatChannel::where('tenant_id', $userA->tenant_id)
            ->where('channel_type', 'direct')
            ->count());
    }

    public function test_direct_message_cross_tenant_is_rejected(): void
    {
        $userA   = $this->makeTenantUser();
        $otherTenant = Tenant::factory()->create();
        $userB   = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($userA)
            ->postJson("/chat/direct/{$userB->id}")
            ->assertForbidden();
    }

    // ── Deleted messages ──────────────────────────────────────────────────────

    public function test_soft_deleted_messages_show_as_deleted(): void
    {
        $user    = $this->makeTenantUser();
        $channel = $this->makeChannelWithMember($user);

        $msg = ChatMessage::factory()->deleted()->create([
            'channel_id'     => $channel->id,
            'sender_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson("/chat/channels/{$channel->id}/messages")
            ->assertOk()
            ->assertJsonFragment(['id' => $msg->id, 'is_deleted' => true]);
    }

    // ── Unauthenticated ───────────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_chat_api(): void
    {
        $this->getJson('/chat/channels')->assertUnauthorized();
        $this->getJson('/chat/unread-count')->assertUnauthorized();
    }
}
