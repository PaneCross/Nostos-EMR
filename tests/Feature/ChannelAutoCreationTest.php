<?php

// ─── ChannelAutoCreationTest ──────────────────────────────────────────────────
// Feature tests for ChatService channel auto-creation.
//
// Coverage:
//   - createDepartmentChannels() creates 14 dept channels + 1 broadcast (15 total)
//   - Each dept channel only adds members from that department
//   - Broadcast channel adds ALL active users
//   - createDepartmentChannels() is idempotent (safe to call twice)
//   - createParticipantIdtChannel() creates 1 participant_idt channel
//   - IDT channel name = "{Participant Full Name} — IDT"
//   - IDT channel includes correct 6 departments' users as members
//   - IDT channel creation is idempotent
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAutoCreationTest extends TestCase
{
    use RefreshDatabase;

    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChatService::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeFullTenant(): array
    {
        $tenant = Tenant::factory()->create();

        // 2 users per department (28 total)
        $depts = [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'activities', 'home_care', 'transportation', 'pharmacy',
            'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
        ];

        $users = collect();
        foreach ($depts as $dept) {
            $users = $users->merge(User::factory()->count(2)->create([
                'tenant_id'  => $tenant->id,
                'department' => $dept,
                'is_active'  => true,
            ]));
        }

        $createdBy = $users->first();

        return ['tenant' => $tenant, 'users' => $users, 'created_by' => $createdBy];
    }

    // ── Department channel creation ───────────────────────────────────────────

    public function test_creates_14_dept_channels_and_1_broadcast(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $this->service->createDepartmentChannels($tenant->id, $createdBy);

        $this->assertEquals(
            15,
            ChatChannel::where('tenant_id', $tenant->id)->count()
        );

        $this->assertEquals(
            14,
            ChatChannel::where('tenant_id', $tenant->id)
                ->where('channel_type', 'department')
                ->count()
        );

        $this->assertEquals(
            1,
            ChatChannel::where('tenant_id', $tenant->id)
                ->where('channel_type', 'broadcast')
                ->count()
        );
    }

    public function test_dept_channel_only_adds_users_from_that_department(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $this->service->createDepartmentChannels($tenant->id, $createdBy);

        $idtChannel = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'department')
            ->where('name', 'Idt')
            ->first();

        $this->assertNotNull($idtChannel);

        $memberIds = ChatMembership::where('channel_id', $idtChannel->id)
            ->pluck('user_id')
            ->toArray();

        // All members should be in the 'idt' department
        $nonIdtMembers = User::whereIn('id', $memberIds)
            ->where('department', '!=', 'idt')
            ->count();

        $this->assertEquals(0, $nonIdtMembers);
        $this->assertEquals(2, count($memberIds)); // 2 idt users
    }

    public function test_broadcast_channel_adds_all_active_users(): void
    {
        ['tenant' => $tenant, 'users' => $users, 'created_by' => $createdBy] = $this->makeFullTenant();

        $this->service->createDepartmentChannels($tenant->id, $createdBy);

        $broadcastChannel = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'broadcast')
            ->first();

        $memberCount = ChatMembership::where('channel_id', $broadcastChannel->id)->count();

        $this->assertEquals($users->count(), $memberCount);
    }

    public function test_create_department_channels_is_idempotent(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $this->service->createDepartmentChannels($tenant->id, $createdBy);
        $this->service->createDepartmentChannels($tenant->id, $createdBy); // second call

        $this->assertEquals(
            15,
            ChatChannel::where('tenant_id', $tenant->id)->count()
        );
    }

    // ── Participant IDT channel ───────────────────────────────────────────────

    public function test_creates_participant_idt_channel_with_correct_name(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'first_name' => 'Evelyn',
            'last_name'  => 'Testpatient',
        ]);

        $channel = $this->service->createParticipantIdtChannel($participant, $createdBy);

        $this->assertInstanceOf(ChatChannel::class, $channel);
        $this->assertEquals('participant_idt', $channel->channel_type);
        $this->assertStringContainsString('Evelyn', $channel->name);
        $this->assertStringContainsString('IDT', $channel->name);
    }

    public function test_idt_channel_includes_correct_departments(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $tenant->id,
            'site_id'   => $site->id,
        ]);

        $channel = $this->service->createParticipantIdtChannel($participant, $createdBy);

        $memberUserIds = ChatMembership::where('channel_id', $channel->id)
            ->pluck('user_id')
            ->toArray();

        $memberDepts = User::whereIn('id', $memberUserIds)
            ->pluck('department')
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $expectedDepts = ['behavioral_health', 'idt', 'pharmacy', 'primary_care', 'social_work', 'therapies'];

        $this->assertEquals($expectedDepts, $memberDepts);
    }

    public function test_participant_idt_channel_creation_is_idempotent(): void
    {
        ['tenant' => $tenant, 'created_by' => $createdBy] = $this->makeFullTenant();

        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $tenant->id,
            'site_id'   => $site->id,
        ]);

        $channel1 = $this->service->createParticipantIdtChannel($participant, $createdBy);
        $channel2 = $this->service->createParticipantIdtChannel($participant, $createdBy);

        $this->assertEquals($channel1->id, $channel2->id);

        $channelCount = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'participant_idt')
            ->count();

        $this->assertEquals(1, $channelCount);
    }
}
