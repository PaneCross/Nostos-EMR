<?php

// ─── IdtMeetingController ─────────────────────────────────────────────────────
// Manages IDT meeting records and participant review queues.
//
// Routes (/idt/meetings):
//   GET  /idt/meetings               — upcoming + recent meetings
//   POST /idt/meetings               — schedule a new meeting
//   GET  /idt/meetings/{id}          — meeting detail with participant queue
//   PUT  /idt/meetings/{id}          — update meeting details (not completed)
//   POST /idt/meetings/{id}/start    — set status = in_progress
//   POST /idt/meetings/{id}/complete — lock meeting, set status = completed
//   POST /idt/meetings/{id}/participants    — add participant to review queue
//   PATCH /idt/meetings/{id}/participants/{review} — update review notes
//   POST  /idt/meetings/{id}/participants/{review}/reviewed — mark as reviewed
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\IdtMeeting;
use App\Models\IdtParticipantReview;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IdtMeetingController extends Controller
{
    private function authorizeForTenant(IdtMeeting $meeting, $user): void
    {
        abort_if($meeting->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /idt/meetings
     * Inertia page: IDT Dashboard with today's meeting + recent + upcoming.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $today = IdtMeeting::where('tenant_id', $user->tenant_id)
            ->today()
            ->with(['facilitator:id,first_name,last_name', 'participantReviews.participant:id,mrn,first_name,last_name'])
            ->orderBy('meeting_time')
            ->get();

        $upcoming = IdtMeeting::where('tenant_id', $user->tenant_id)
            ->upcoming()
            ->where('meeting_date', '>', now()->toDateString())
            ->with('facilitator:id,first_name,last_name')
            ->orderBy('meeting_date')
            ->limit(10)
            ->get();

        $recent = IdtMeeting::where('tenant_id', $user->tenant_id)
            ->completed()
            ->with('facilitator:id,first_name,last_name')
            ->orderByDesc('meeting_date')
            ->limit(5)
            ->get();

        return Inertia::render('Idt/Dashboard', [
            'todayMeetings'  => $today,
            'upcomingMeetings' => $upcoming,
            'recentMeetings' => $recent,
        ]);
    }

    /**
     * POST /idt/meetings
     * Schedule a new IDT meeting.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'meeting_date'       => ['required', 'date'],
            'meeting_time'       => ['nullable', 'date_format:H:i'],
            'meeting_type'       => ['required', Rule::in(IdtMeeting::TYPES)],
            'facilitator_user_id'=> ['nullable', 'integer', 'exists:shared_users,id'],
            'site_id'            => ['nullable', 'integer', 'exists:shared_sites,id'],
        ]);

        $meeting = IdtMeeting::create(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status'    => 'scheduled',
        ]));

        AuditLog::record(
            action: 'idt.meeting.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'idt_meeting',
            resourceId: $meeting->id,
            description: "{$meeting->typeLabel()} meeting scheduled for {$meeting->meeting_date}",
        );

        return response()->json($meeting->load('facilitator:id,first_name,last_name'), 201);
    }

    /**
     * GET /idt/meetings/{meeting}
     * Inertia page: Meeting runner with participant queue.
     */
    public function show(Request $request, IdtMeeting $meeting): Response
    {
        $this->authorizeForTenant($meeting, $request->user());

        $meeting->load([
            'facilitator:id,first_name,last_name',
            'participantReviews.participant:id,mrn,first_name,last_name,dob',
        ]);

        return Inertia::render('Idt/RunMeeting', [
            'meeting' => $meeting,
        ]);
    }

    /**
     * PATCH /idt/meetings/{meeting}
     * Update meeting details (minutes, decisions, attendees). Not allowed on completed.
     */
    public function update(Request $request, IdtMeeting $meeting): JsonResponse
    {
        $this->authorizeForTenant($meeting, $request->user());
        abort_if($meeting->isLocked(), 403, 'Completed meetings cannot be edited.');

        $validated = $request->validate([
            'minutes_text' => ['nullable', 'string'],
            'decisions'    => ['nullable', 'array'],
            'attendees'    => ['nullable', 'array'],
            'attendees.*'  => ['integer', 'exists:shared_users,id'],
        ]);

        $meeting->update($validated);

        return response()->json($meeting->refresh());
    }

    /**
     * POST /idt/meetings/{meeting}/start
     * Sets status to in_progress.
     */
    public function start(Request $request, IdtMeeting $meeting): JsonResponse
    {
        $this->authorizeForTenant($meeting, $request->user());
        abort_unless($meeting->status === 'scheduled', 422, 'Only scheduled meetings can be started.');

        $meeting->update(['status' => 'in_progress']);

        return response()->json($meeting->refresh());
    }

    /**
     * POST /idt/meetings/{meeting}/complete
     * Locks the meeting (status = completed). Once completed, no further edits.
     */
    public function complete(Request $request, IdtMeeting $meeting): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($meeting, $user);
        abort_unless($meeting->status === 'in_progress', 422, 'Only in-progress meetings can be completed.');

        $meeting->update(['status' => 'completed']);

        AuditLog::record(
            action: 'idt.meeting.completed',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'idt_meeting',
            resourceId: $meeting->id,
            description: "{$meeting->typeLabel()} meeting on {$meeting->meeting_date} completed and locked",
        );

        return response()->json($meeting->refresh());
    }

    /**
     * POST /idt/meetings/{meeting}/participants
     * Add a participant to the review queue.
     */
    public function addParticipant(Request $request, IdtMeeting $meeting): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($meeting, $user);
        abort_if($meeting->isLocked(), 403, 'Cannot add participants to a completed meeting.');

        $validated = $request->validate([
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'presenting_discipline'=> ['nullable', 'string', 'max:50'],
        ]);

        // Determine next queue order
        $nextOrder = $meeting->participantReviews()->max('queue_order') + 1;

        $review = IdtParticipantReview::firstOrCreate(
            ['meeting_id' => $meeting->id, 'participant_id' => $validated['participant_id']],
            ['queue_order' => $nextOrder, 'presenting_discipline' => $validated['presenting_discipline'] ?? null],
        );

        return response()->json(
            $review->load('participant:id,mrn,first_name,last_name,dob'),
            201
        );
    }

    /**
     * PATCH /idt/meetings/{meeting}/participants/{review}
     * Update review notes and action items for a participant review.
     */
    public function updateReview(Request $request, IdtMeeting $meeting, IdtParticipantReview $review): JsonResponse
    {
        $this->authorizeForTenant($meeting, $request->user());
        abort_if($meeting->isLocked(), 403, 'Cannot edit a completed meeting.');
        abort_if($review->meeting_id !== $meeting->id, 404);

        $validated = $request->validate([
            'summary_text'         => ['nullable', 'string'],
            'action_items'         => ['nullable', 'array'],
            'action_items.*.description'       => ['required_with:action_items', 'string'],
            'action_items.*.assigned_to_dept'  => ['nullable', 'string'],
            'action_items.*.due_date'          => ['nullable', 'date'],
            'status_change_noted'  => ['nullable', 'boolean'],
            'queue_order'          => ['nullable', 'integer', 'min:0'],
        ]);

        $review->update($validated);

        return response()->json($review->refresh()->load('participant:id,mrn,first_name,last_name'));
    }

    /**
     * POST /idt/meetings/{meeting}/participants/{review}/reviewed
     * Mark a participant review as completed (reviewed_at = now).
     */
    public function markReviewed(Request $request, IdtMeeting $meeting, IdtParticipantReview $review): JsonResponse
    {
        $this->authorizeForTenant($meeting, $request->user());
        abort_if($meeting->isLocked() && $meeting->status !== 'in_progress', 403);
        abort_if($review->meeting_id !== $meeting->id, 404);

        $review->markReviewed();

        return response()->json($review->refresh()->load('participant:id,mrn,first_name,last_name'));
    }
}
