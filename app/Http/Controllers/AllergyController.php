<?php

// ─── AllergyController ────────────────────────────────────────────────────────
// Manages drug, food, and environmental allergies and dietary restrictions.
// Life-threatening entries are counted in ParticipantController::show() and
// displayed as a red banner on the participant profile.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreAllergyRequest;
use App\Models\Allergy;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllergyController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    private function authorizeAllergyForParticipant(Allergy $allergy, Participant $participant): void
    {
        abort_if($allergy->participant_id !== $participant->id, 404);
    }

    /**
     * GET /participants/{participant}/allergies
     * Returns all active allergies grouped by allergy_type.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $allergies = $participant->allergies()
            ->with('verifiedBy:id,first_name,last_name')
            ->orderByRaw("CASE severity WHEN 'life_threatening' THEN 0 WHEN 'severe' THEN 1 WHEN 'moderate' THEN 2 WHEN 'mild' THEN 3 ELSE 4 END")
            ->get()
            ->groupBy('allergy_type');

        return response()->json($allergies);
    }

    /**
     * POST /participants/{participant}/allergies
     * Adds an allergy or dietary restriction record.
     */
    public function store(StoreAllergyRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $allergy = Allergy::create(array_merge($request->validated(), [
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'is_active'      => true,
        ]));

        AuditLog::record(
            action: 'participant.allergy.added',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Allergy '{$allergy->allergen_name}' ({$allergy->severity}) added to {$participant->mrn}",
            newValues: $request->validated(),
        );

        return response()->json($allergy, 201);
    }

    /**
     * PUT /participants/{participant}/allergies/{allergy}
     * Updates an allergy record (e.g., deactivate, update severity).
     */
    public function update(StoreAllergyRequest $request, Participant $participant, Allergy $allergy): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeAllergyForParticipant($allergy, $participant);

        $old = $allergy->only(array_keys($request->validated()));
        $allergy->update($request->validated());

        AuditLog::record(
            action: 'participant.allergy.updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Allergy '{$allergy->allergen_name}' updated for {$participant->mrn}",
            oldValues: $old,
            newValues: $request->validated(),
        );

        return response()->json($allergy->fresh());
    }
}
