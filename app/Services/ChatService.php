<?php

// ─── ChatService ──────────────────────────────────────────────────────────────
// Handles channel lifecycle:
//   - createDepartmentChannels(): called on tenant setup — 14 dept channels + 1 broadcast
//   - createParticipantIdtChannel(): called on participant enrollment
//   - getOrCreateDmChannel(): finds or creates a DM channel between two users
//   - addMembersToChannel(): bulk-adds users to a channel
//   - getDepartmentUserIds(): fetches all active user IDs for a dept in a tenant
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChatService
{
    /** All 14 department slugs. */
    private const DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'activities', 'home_care', 'transportation', 'pharmacy',
        'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
    ];

    /**
     * IDT participant channel members: these departments join every
     * participant_idt channel automatically on enrollment.
     */
    private const IDT_CHANNEL_DEPARTMENTS = [
        'idt', 'primary_care', 'social_work', 'therapies', 'pharmacy', 'behavioral_health',
    ];

    /**
     * Create the standard set of channels for a new tenant:
     *  - 14 department channels (one per dept, all users in that dept added)
     *  - 1 broadcast channel (all users in the tenant added)
     *
     * Idempotent: skips channel types / departments that already exist.
     *
     * @param  int   $tenantId   The tenant to create channels for.
     * @param  User  $createdBy  The user creating the channels (e.g. the provisioning SA).
     */
    public function createDepartmentChannels(int $tenantId, User $createdBy): void
    {
        // ── Broadcast channel ─────────────────────────────────────────────────
        $broadcast = ChatChannel::firstOrCreate(
            ['tenant_id' => $tenantId, 'channel_type' => 'broadcast'],
            [
                'name'               => 'All Staff',
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]
        );

        $allUserIds = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        $this->addMembersToChannel($broadcast, $allUserIds);

        // ── Department channels ────────────────────────────────────────────────
        foreach (self::DEPARTMENTS as $dept) {
            $deptLabel = ucwords(str_replace('_', ' ', $dept));

            $channel = ChatChannel::firstOrCreate(
                [
                    'tenant_id'    => $tenantId,
                    'channel_type' => 'department',
                    'name'         => $deptLabel,
                ],
                [
                    'created_by_user_id' => $createdBy->id,
                    'is_active'          => true,
                ]
            );

            $deptUserIds = $this->getDepartmentUserIds($tenantId, $dept);
            $this->addMembersToChannel($channel, $deptUserIds);
        }
    }

    /**
     * Create a participant_idt channel for a newly enrolled participant.
     * Members: all active users from IDT_CHANNEL_DEPARTMENTS in the tenant.
     *
     * Returns the (possibly already-existing) channel.
     *
     * @param  Participant  $participant  The newly enrolled participant.
     * @param  User         $createdBy   The user triggering enrollment.
     */
    public function createParticipantIdtChannel(Participant $participant, User $createdBy): ChatChannel
    {
        $channelName = trim($participant->first_name . ' ' . $participant->last_name) . ' — IDT';

        $channel = ChatChannel::firstOrCreate(
            [
                'tenant_id'      => $participant->tenant_id,
                'channel_type'   => 'participant_idt',
                'participant_id' => $participant->id,
            ],
            [
                'name'               => $channelName,
                'created_by_user_id' => $createdBy->id,
                'is_active'          => true,
            ]
        );

        $memberIds = User::where('tenant_id', $participant->tenant_id)
            ->where('is_active', true)
            ->whereIn('department', self::IDT_CHANNEL_DEPARTMENTS)
            ->pluck('id')
            ->toArray();

        $this->addMembersToChannel($channel, $memberIds);

        return $channel;
    }

    /**
     * Find or create a direct-message channel between two users.
     * Uses a subquery to find a channel that has BOTH users as members.
     *
     * @param  User  $userA
     * @param  User  $userB
     * @param  int   $tenantId
     * @return ChatChannel
     */
    public function getOrCreateDmChannel(User $userA, User $userB, int $tenantId): ChatChannel
    {
        // Find an existing direct channel shared by both users
        $existing = ChatChannel::where('tenant_id', $tenantId)
            ->where('channel_type', 'direct')
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $userA->id))
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $userB->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        // Create a new DM channel
        $channel = ChatChannel::create([
            'tenant_id'          => $tenantId,
            'channel_type'       => 'direct',
            'name'               => null,
            'created_by_user_id' => $userA->id,
            'is_active'          => true,
        ]);

        $this->addMembersToChannel($channel, [$userA->id, $userB->id]);

        return $channel;
    }

    /**
     * Bulk-insert memberships, ignoring duplicates (idempotent).
     *
     * @param  ChatChannel  $channel
     * @param  int[]        $userIds
     */
    public function addMembersToChannel(ChatChannel $channel, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $now  = Carbon::now();
        $rows = [];

        foreach ($userIds as $userId) {
            $rows[] = [
                'channel_id' => $channel->id,
                'user_id'    => $userId,
                'joined_at'  => $now,
            ];
        }

        // insertOrIgnore respects the UNIQUE(channel_id, user_id) constraint
        DB::table('emr_chat_memberships')->insertOrIgnore($rows);
    }

    /**
     * Return IDs of all active users in a given department for a tenant.
     *
     * @param  int     $tenantId
     * @param  string  $department
     * @return int[]
     */
    public function getDepartmentUserIds(int $tenantId, string $department): array
    {
        return User::where('tenant_id', $tenantId)
            ->where('department', $department)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }
}
