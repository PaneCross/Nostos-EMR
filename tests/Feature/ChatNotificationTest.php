<?php

// ─── ChatNotificationTest ─────────────────────────────────────────────────────
// Tests the notification / alert side-effects of sending chat messages:
//   - Standard message: no alert created (broadcast only)
//   - Urgent message: creates a critical-severity alert for channel members
//   - Alert title includes sender's name
//   - Alert metadata.channel_id enables deep-link to /chat?channel={id}
//   - Alert target_departments matches the departments of non-sender members
//   - Urgent message to channel with no other members: no alert created
//   - Standard message: NewChatMessage event still broadcasts
//   - Urgent message: NewChatMessage event also broadcasts
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Events\NewChatMessage;
use App\Models\Alert;
use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeTenantUser(string $dept = 'idt', ?int $tenantId = null): User
    {
        $tid = $tenantId ?? Tenant::factory()->create()->id;
        return User::factory()->create([
            'tenant_id'  => $tid,
            'department' => $dept,
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    private function makeChannel(int $tenantId, User $creator): ChatChannel
    {
        return ChatChannel::factory()->create([
            'tenant_id'          => $tenantId,
            'channel_type'       => 'department',
            'name'               => 'Test Channel',
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function addMember(ChatChannel $channel, User $user): void
    {
        ChatMembership::factory()->create([
            'channel_id' => $channel->id,
            'user_id'    => $user->id,
        ]);
    }

    // ── Standard messages: no alert ────────────────────────────────────────────

    public function test_standard_message_does_not_create_alert(): void
    {
        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('primary_care', $tenant->id);
        $member  = $this->makeTenantUser('social_work', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Just a regular message',
            'priority'     => 'standard',
        ])->assertCreated();

        $this->assertDatabaseCount('emr_alerts', 0);
    }

    // ── Urgent messages: creates critical alert ────────────────────────────────

    public function test_urgent_message_creates_critical_alert(): void
    {
        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('primary_care', $tenant->id);
        $member  = $this->makeTenantUser('social_work', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'URGENT: patient fell',
            'priority'     => 'urgent',
        ])->assertCreated();

        $this->assertDatabaseCount('emr_alerts', 1);

        $alert = Alert::first();
        $this->assertEquals('critical', $alert->severity);
        $this->assertEquals('chat', $alert->source_module);
        $this->assertEquals('urgent_chat_message', $alert->alert_type);
    }

    public function test_urgent_message_alert_title_contains_sender_name(): void
    {
        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('primary_care', $tenant->id);
        $member  = $this->makeTenantUser('social_work', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Urgent update',
            'priority'     => 'urgent',
        ])->assertCreated();

        $alert = Alert::first();
        $this->assertStringContainsString($sender->first_name, $alert->title);
        $this->assertStringContainsString($sender->last_name, $alert->title);
    }

    // ── Alert metadata: channel_id for deep-linking ───────────────────────────

    public function test_urgent_alert_metadata_contains_channel_id(): void
    {
        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('idt', $tenant->id);
        $member  = $this->makeTenantUser('social_work', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Please review immediately',
            'priority'     => 'urgent',
        ])->assertCreated();

        $alert = Alert::first();
        $this->assertNotNull($alert->metadata);
        $this->assertEquals($channel->id, $alert->metadata['channel_id']);
    }

    // ── Alert target departments ───────────────────────────────────────────────

    public function test_urgent_alert_targets_member_departments_not_sender(): void
    {
        $tenant   = Tenant::factory()->create();
        $sender   = $this->makeTenantUser('primary_care', $tenant->id);
        $member1  = $this->makeTenantUser('dietary', $tenant->id);
        $member2  = $this->makeTenantUser('social_work', $tenant->id);
        $channel  = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member1);
        $this->addMember($channel, $member2);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Team alert',
            'priority'     => 'urgent',
        ])->assertCreated();

        $alert = Alert::first();
        $depts = $alert->target_departments;

        $this->assertContains('dietary', $depts);
        $this->assertContains('social_work', $depts);
        $this->assertNotContains('primary_care', $depts); // sender excluded
    }

    // ── Edge case: no other members → no alert ────────────────────────────────

    public function test_urgent_message_with_no_other_members_creates_no_alert(): void
    {
        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('primary_care', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender); // only the sender

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Anyone there?',
            'priority'     => 'urgent',
        ])->assertCreated();

        $this->assertDatabaseCount('emr_alerts', 0);
    }

    // ── Broadcasting ──────────────────────────────────────────────────────────

    public function test_standard_message_broadcasts_new_chat_message_event(): void
    {
        Event::fake([NewChatMessage::class]);

        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('idt', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'Hello world',
        ])->assertCreated();

        Event::assertDispatched(NewChatMessage::class);
    }

    public function test_urgent_message_broadcasts_new_chat_message_event(): void
    {
        Event::fake([NewChatMessage::class]);

        $tenant  = Tenant::factory()->create();
        $sender  = $this->makeTenantUser('idt', $tenant->id);
        $member  = $this->makeTenantUser('behavioral_health', $tenant->id);
        $channel = $this->makeChannel($tenant->id, $sender);
        $this->addMember($channel, $sender);
        $this->addMember($channel, $member);

        $this->actingAs($sender)->postJson("/chat/channels/{$channel->id}/messages", [
            'message_text' => 'URGENT: please respond',
            'priority'     => 'urgent',
        ])->assertCreated();

        Event::assertDispatched(NewChatMessage::class);
    }
}
