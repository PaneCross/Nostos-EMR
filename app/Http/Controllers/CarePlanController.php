<?php

// ─── CarePlanController ───────────────────────────────────────────────────────
// Manages CMS-compliant care plans for PACE participants.
//
// Routes (nested under /participants/{participant}/careplan):
//   GET    /careplan                              — active plan with goals
//   POST   /careplan                              — create a new draft plan
//   GET    /careplan/{id}                         — specific plan version with goals
//   PUT    /careplan/{id}/goals/{domain}          — upsert a domain goal
//   POST   /careplan/{id}/approve                 — approve plan (IDT/PC Admin only)
//   POST   /careplan/{id}/new-version             — create new draft version
//   PATCH  /careplan/{id}/participation           — record participant offer/response (W4-5)
//
// W4-5: approve() now enforces 42 CFR §460.104(d) — participation must be offered
// before a plan can be approved. A warning is returned if the field is missing,
// but approval proceeds (soft enforcement per CMS survey guidance).
//
// Broadcasts CarePlanUpdatedEvent on goal changes for real-time chart refresh.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\CarePlanUpdatedEvent;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CarePlanController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/careplan
     * Returns the active care plan with all goals, or most recent draft if no active plan.
     */
    public function show(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $plan = CarePlan::where('participant_id', $participant->id)
            ->whereIn('status', ['active', 'under_review'])
            ->with(['goals.authoredBy:id,first_name,last_name', 'approvedBy:id,first_name,last_name'])
            ->orderByDesc('version')
            ->first()
            ?? CarePlan::where('participant_id', $participant->id)
                ->where('status', 'draft')
                ->with(['goals.authoredBy:id,first_name,last_name'])
                ->orderByDesc('version')
                ->first();

        AuditLog::record(
            action: 'participant.careplan.viewed',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan viewed for {$participant->mrn}",
        );

        return response()->json($plan);
    }

    /**
     * GET /participants/{participant}/careplan/{carePlan}
     * Returns a specific care plan version with all goals.
     */
    public function showVersion(Request $request, Participant $participant, CarePlan $carePlan): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);

        return response()->json(
            $carePlan->load(['goals.authoredBy:id,first_name,last_name', 'approvedBy:id,first_name,last_name'])
        );
    }

    /**
     * POST /participants/{participant}/careplan
     * Creates a new draft care plan. One participant can have multiple versions.
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $validated = $request->validate([
            'overall_goals_text' => ['nullable', 'string'],
        ]);

        $nextVersion = CarePlan::where('participant_id', $participant->id)->max('version') + 1;

        $plan = CarePlan::create([
            'participant_id'     => $participant->id,
            'tenant_id'          => $user->tenant_id,
            'version'            => $nextVersion,
            'status'             => 'draft',
            'overall_goals_text' => $validated['overall_goals_text'] ?? null,
        ]);

        AuditLog::record(
            action: 'participant.careplan.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan v{$plan->version} created for {$participant->mrn}",
        );

        return response()->json($plan->load('goals'), 201);
    }

    /**
     * PUT /participants/{participant}/careplan/{carePlan}/goals/{domain}
     * Creates or updates a single domain goal on a draft care plan.
     * Only editable plans (draft or under_review) can be modified.
     */
    public function upsertGoal(Request $request, Participant $participant, CarePlan $carePlan, string $domain): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);
        abort_unless($carePlan->isEditable(), 403, 'Only draft or under-review care plans can be edited.');
        abort_unless(in_array($domain, CarePlanGoal::DOMAINS, true), 422, 'Invalid domain.');

        $validated = $request->validate([
            'goal_description'    => ['required', 'string'],
            'target_date'         => ['nullable', 'date'],
            'measurable_outcomes' => ['nullable', 'string'],
            'interventions'       => ['nullable', 'string'],
            'status'              => ['nullable', Rule::in(CarePlanGoal::STATUSES)],
        ]);

        $goal = CarePlanGoal::updateOrCreate(
            ['care_plan_id' => $carePlan->id, 'domain' => $domain],
            array_merge($validated, [
                'authored_by_user_id'      => $user->id,
                'last_updated_by_user_id'  => $user->id,
            ])
        );

        AuditLog::record(
            action: 'participant.careplan.goal_updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan goal '{$domain}' updated for {$participant->mrn}",
            newValues: $validated,
        );

        // Phase 4: broadcast for real-time chart CarePlan tab refresh
        broadcast(new CarePlanUpdatedEvent($carePlan, $domain, $user->department))->toOthers();

        return response()->json($goal->load('authoredBy:id,first_name,last_name'));
    }

    /**
     * POST /participants/{participant}/careplan/{carePlan}/approve
     * Approves a draft or under_review care plan.
     * Restricted to IDT Admin + Primary Care Admin.
     *
     * W4-5: 42 CFR §460.104(d) — participation must be documented before approval.
     * This is a soft enforcement: the plan is approved but a 'participation_warning'
     * flag in the response signals the UI to surface a reminder to staff.
     */
    public function approve(Request $request, Participant $participant, CarePlan $carePlan): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);
        abort_unless($carePlan->canBeApprovedBy($user), 403, 'Only IDT Admin or Primary Care Admin may approve care plans.');

        // W4-5: Check participation documentation before editability guard
        $participationWarning = ! $carePlan->participant_offered_participation;

        abort_unless($carePlan->isEditable(), 422, 'Only draft or under-review care plans can be approved.');

        $carePlan->approve($user);

        AuditLog::record(
            action: 'participant.careplan.approved',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan v{$carePlan->version} approved for {$participant->mrn}"
                . ($participationWarning ? ' (participation not documented — 42 CFR §460.104(d))' : ''),
            newValues: [
                'care_plan_id'          => $carePlan->id,
                'effective_date'        => $carePlan->effective_date,
                'participation_warning' => $participationWarning,
            ],
        );

        broadcast(new CarePlanUpdatedEvent($carePlan->refresh(), 'all', $user->department))->toOthers();

        return response()->json(array_merge(
            $carePlan->fresh(['goals', 'approvedBy:id,first_name,last_name'])->toArray(),
            ['participation_warning' => $participationWarning]
        ));
    }

    /**
     * PATCH /participants/{participant}/careplan/{carePlan}/participation
     * Records whether the participant was offered the opportunity to participate
     * in care plan development and their response.
     * 42 CFR §460.104(d): PACE must offer participation to participant/representative.
     * Any authenticated user with canEdit access may record this.
     */
    public function updateParticipation(Request $request, Participant $participant, CarePlan $carePlan): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);

        $validated = $request->validate([
            'participant_offered_participation' => ['required', 'boolean'],
            'participant_response'              => ['nullable', 'string', Rule::in(['accepted', 'declined', 'no_response'])],
            'offered_at'                        => ['nullable', 'date'],
        ]);

        $carePlan->update(array_merge($validated, [
            'offered_by_user_id' => $user->id,
            'offered_at'         => $validated['offered_at'] ?? now(),
        ]));

        AuditLog::record(
            action: 'participant.careplan.participation_updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Participation documented for care plan v{$carePlan->version} ({$participant->mrn}): "
                . ($validated['participant_offered_participation'] ? 'offered' : 'not offered')
                . ($validated['participant_response'] ? ', response: ' . $validated['participant_response'] : ''),
            newValues: $validated,
        );

        return response()->json($carePlan->fresh(['offeredBy:id,first_name,last_name']));
    }

    /**
     * POST /participants/{participant}/careplan/{carePlan}/new-version
     * Creates a new draft version from the given plan, copying all active goals.
     * The source plan is moved to 'under_review'.
     */
    public function newVersion(Request $request, Participant $participant, CarePlan $carePlan): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);
        abort_unless(in_array($carePlan->status, ['active', 'under_review'], true), 422, 'Can only create a new version from an active or under-review plan.');

        $newPlan = $carePlan->createNewVersion($user);

        AuditLog::record(
            action: 'participant.careplan.new_version',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan v{$newPlan->version} created from v{$carePlan->version} for {$participant->mrn}",
        );

        return response()->json($newPlan->load('goals'), 201);
    }
}
