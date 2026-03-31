<?php

// ─── ChatController ───────────────────────────────────────────────────────────
// Handles the chat API (JSON) and the Inertia page render.
//
// All API endpoints require the authenticated user to be a member of the
// channel being accessed (enforced by requireMembership()).
//
// Routes:
//   GET  /chat                          → index()         Inertia page
//   GET  /chat/users/search?q={term}    → searchUsers()   JSON: tenant user search (DM typeahead)
//   GET  /chat/channels                 → channels()      JSON: my channels + unread counts
//   GET  /chat/channels/{id}/messages   → messages()      JSON: paginated messages
//   POST /chat/channels/{id}/messages   → send()          JSON: new message + Reverb broadcast
//   POST /chat/channels/{id}/read       → markRead()      JSON: mark last_read_at = now()
//   POST /chat/direct/{userId}          → directMessage() JSON: get/create DM channel
//   GET  /chat/unread-count             → unreadCount()   JSON: total unread across all channels
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\ChatActivityEvent;
use App\Events\NewChatMessage;
use App\Models\AuditLog;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService  $chatService,
        private readonly AlertService $alertService,
    ) {}

    // ── Inertia page ──────────────────────────────────────────────────────────

    /**
     * Render the /chat Inertia page.
     * Initial channel list is passed as a prop to avoid an extra round-trip.
     */
    public function index(Request $request): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => 'chat.view',
            'resource_type' => 'chat',
            'resource_id'   => null,
            'tenant_id'     => $user->tenant_id,
            'ip_address'    => $request->ip(),
        ]);

        return Inertia::render('Chat/Index');
    }

    // ── API endpoints (JSON) ──────────────────────────────────────────────────

    /**
     * Search users within the current tenant for the DM user-search typeahead.
     * Requires at least 2 characters. Returns max 20 results, excluding self.
     * Used by the frontend "New Message" DM search field in Chat/Index.tsx.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['users' => []]);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        $results = User::where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $like = '%' . strtolower($q) . '%';
                $query->whereRaw("LOWER(first_name) LIKE ?", [$like])
                      ->orWhereRaw("LOWER(last_name) LIKE ?", [$like])
                      ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", [$like]);
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'department', 'role'])
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->first_name . ' ' . $u->last_name,
                'department' => $u->department,
                'role'       => $u->role,
            ]);

        return response()->json(['users' => $results]);
    }

    /**
     * List all channels the authenticated user belongs to, grouped by type,
     * with unread message counts.
     */
    public function channels(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $channels = ChatChannel::active()
            ->forUser($user)
            ->with(['memberships' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('channel_type')
            ->orderBy('name')
            ->get()
            ->map(function (ChatChannel $ch) use ($user) {
                $membership = $ch->memberships->first();
                $unreadQuery = $ch->messages()
                    ->withoutTrashed()
                    ->when($membership?->last_read_at, fn ($q) => $q->where('sent_at', '>', $membership->last_read_at));

                $unread       = (clone $unreadQuery)->count();
                $urgentUnread = (clone $unreadQuery)->where('priority', 'urgent')->count();

                return [
                    'id'                  => $ch->id,
                    'channel_type'        => $ch->channel_type,
                    'name'                => $ch->displayName($user),
                    'unread_count'        => $unread,
                    'urgent_unread_count' => $urgentUnread,
                    'is_active'           => $ch->is_active,
                ];
            });

        return response()->json(['channels' => $channels]);
    }

    /**
     * Paginated message history for a channel (newest first).
     * Page size = 50. Soft-deleted messages included with is_deleted=true.
     */
    public function messages(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $messages = $channel->messages()
            ->withTrashed()
            ->with('sender')
            ->orderBy('sent_at', 'desc')
            ->paginate(50)
            ->through(fn (ChatMessage $m) => $m->toApiArray());

        return response()->json([
            'messages'     => $messages->items(),
            'current_page' => $messages->currentPage(),
            'last_page'    => $messages->lastPage(),
        ]);
    }

    /**
     * Send a message to a channel and broadcast via Reverb.
     * If priority=urgent, creates a critical severity alert for all non-sender
     * channel members, with metadata.channel_id for deep-link navigation to
     * /chat?channel={id} from the notification bell or critical banner.
     */
    public function send(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        $data = $request->validate([
            'message_text' => ['required', 'string', 'max:4000'],
            'priority'     => ['sometimes', 'in:standard,urgent'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $message = ChatMessage::create([
            'channel_id'     => $channel->id,
            'sender_user_id' => $user->id,
            'message_text'   => $data['message_text'],
            'priority'       => $data['priority'] ?? 'standard',
            'sent_at'        => Carbon::now(),
        ]);

        $message->load('sender');

        // Broadcast message to all channel members via Reverb
        broadcast(new NewChatMessage($message));

        // Notify each non-sender member's personal channel so their nav badge updates
        $channel->memberships()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->each(fn ($memberId) => broadcast(new ChatActivityEvent($memberId, $channel->id)));

        // Urgent messages create a critical alert so all channel members see a
        // full-width banner. metadata.channel_id enables deep-link to the channel.
        if (($data['priority'] ?? 'standard') === 'urgent') {
            $memberDepts = $channel->memberships()
                ->where('user_id', '!=', $user->id)
                ->join('shared_users', 'emr_chat_memberships.user_id', '=', 'shared_users.id')
                ->pluck('shared_users.department')
                ->unique()
                ->values()
                ->toArray();

            if (! empty($memberDepts)) {
                $this->alertService->create([
                    'tenant_id'          => $user->tenant_id,
                    'source_module'      => 'chat',
                    'alert_type'         => 'urgent_chat_message',
                    'title'              => 'Urgent message from ' . $user->first_name . ' ' . $user->last_name,
                    'message'            => mb_strimwidth($data['message_text'], 0, 200, '…'),
                    'severity'           => 'critical',
                    'target_departments' => $memberDepts,
                    'created_by_system'  => false,
                    'created_by_user_id' => $user->id,
                    'metadata'           => ['channel_id' => $channel->id],
                ]);
            }
        }

        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => 'chat.message.send',
            'resource_type' => 'chat_message',
            'resource_id'   => $message->id,
            'tenant_id'     => $user->tenant_id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json(['message' => $message->toApiArray()], 201);
    }

    /**
     * Mark the channel as read for the authenticated user (update last_read_at).
     */
    public function markRead(Request $request, ChatChannel $channel): JsonResponse
    {
        $this->requireMembership($request->user(), $channel);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $channel->memberships()
            ->where('user_id', $user->id)
            ->update(['last_read_at' => Carbon::now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Get or create a direct-message channel with another user.
     * Both users must be in the same tenant.
     */
    public function directMessage(Request $request, User $user): JsonResponse
    {
        /** @var \App\Models\User $viewer */
        $viewer = $request->user();

        if ($viewer->tenant_id !== $user->tenant_id) {
            abort(403, 'Cross-tenant DMs are not permitted.');
        }

        if ($viewer->id === $user->id) {
            abort(422, 'Cannot start a DM with yourself.');
        }

        $channel = $this->chatService->getOrCreateDmChannel($viewer, $user, $viewer->tenant_id);

        return response()->json([
            'channel' => [
                'id'           => $channel->id,
                'channel_type' => $channel->channel_type,
                'name'         => $channel->displayName($viewer),
            ],
        ]);
    }

    /**
     * Total unread message count across all channels the user belongs to.
     * Used for the notification badge in the nav bar.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $total = 0;

        $memberships = $user->chatMemberships()
            ->with('channel')
            ->get();

        foreach ($memberships as $membership) {
            if (! $membership->channel?->is_active) {
                continue;
            }

            $query = $membership->channel
                ->messages()
                ->withoutTrashed();

            if ($membership->last_read_at) {
                $query->where('sent_at', '>', $membership->last_read_at);
            }

            $total += $query->count();
        }

        return response()->json(['unread_count' => $total]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Abort 403 if the given user is not a member of the channel.
     */
    private function requireMembership(User $user, ChatChannel $channel): void
    {
        $isMember = $channel->memberships()
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this channel.');
        }
    }
}
