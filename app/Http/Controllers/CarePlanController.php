<?php

// ─── CarePlanController ───────────────────────────────────────────────────────
// Manages CMS-compliant care plans for PACE participants.
//
// Routes (nested under /participants/{participant}/careplan):
//   GET    /careplan          — active care plan with all goals
//   POST   /careplan          — create a new draft plan
//   GET    /careplan/{id}     — specific plan version with goals
//   PUT    /careplan/{id}/goals/{domain} — upsert a domain goal
//   POST   /careplan/{id}/approve        — approve plan (IDT/PC Admin only)
//   POST   /careplan/{id}/new-version    — create new draft version
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
     */
    public function approve(Request $request, Participant $participant, CarePlan $carePlan): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($carePlan->participant_id !== $participant->id, 404);
        abort_unless($carePlan->canBeApprovedBy($user), 403, 'Only IDT Admin or Primary Care Admin may approve care plans.');
        abort_unless($carePlan->isEditable(), 422, 'Only draft or under-review care plans can be approved.');

        $carePlan->approve($user);

        AuditLog::record(
            action: 'participant.careplan.approved',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Care plan v{$carePlan->version} approved for {$participant->mrn}",
            newValues: ['care_plan_id' => $carePlan->id, 'effective_date' => $carePlan->effective_date],
        );

        broadcast(new CarePlanUpdatedEvent($carePlan->refresh(), 'all', $user->department))->toOthers();

        return response()->json($carePlan->fresh(['goals', 'approvedBy:id,first_name,last_name']));
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
