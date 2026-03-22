<?php

// ─── SocialDeterminantController ──────────────────────────────────────────────
// Manages Social Determinants of Health (SDOH) screening records.
// USCDI v3 data class: Social Determinants of Health.
// Maps to FHIR Observation resources via SdohObservationMapper.
//
// GET  /participants/{id}/social-determinants   → index()  JSON list (newest first)
// POST /participants/{id}/social-determinants   → store()  Record new screening
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\SocialDeterminant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialDeterminantController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/social-determinants
     * Returns all SDOH screenings newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $records = $participant->socialDeterminants()
            ->with('assessedBy:id,first_name,last_name')
            ->orderByDesc('assessed_at')
            ->get();

        return response()->json($records);
    }

    /**
     * POST /participants/{participant}/social-determinants
     * Records a new SDOH screening assessment.
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $validated = $request->validate([
            'assessed_at'           => ['nullable', 'date'],
            'housing_stability'     => ['required', 'string', 'in:' . implode(',', SocialDeterminant::HOUSING_VALUES)],
            'food_security'         => ['required', 'string', 'in:' . implode(',', SocialDeterminant::FOOD_VALUES)],
            'transportation_access' => ['required', 'string', 'in:' . implode(',', SocialDeterminant::TRANSPORT_VALUES)],
            'social_isolation_risk' => ['required', 'string', 'in:' . implode(',', SocialDeterminant::ISOLATION_VALUES)],
            'caregiver_strain'      => ['required', 'string', 'in:' . implode(',', SocialDeterminant::STRAIN_VALUES)],
            'financial_strain'      => ['required', 'string', 'in:' . implode(',', SocialDeterminant::STRAIN_VALUES)],
            'safety_concerns'       => ['nullable', 'string', 'max:1000'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ]);

        $record = SocialDeterminant::create(array_merge($validated, [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'assessed_by_user_id' => $user->id,
            'assessed_at'         => $validated['assessed_at'] ?? now(),
        ]));

        $riskFlag = $record->hasElevatedRisk() ? ' [ELEVATED RISK]' : '';
        AuditLog::record(
            action:       'participant.sdoh.recorded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "SDOH screening recorded for {$participant->mrn}{$riskFlag}",
            newValues: [
                'housing_stability'     => $validated['housing_stability'],
                'food_security'         => $validated['food_security'],
                'transportation_access' => $validated['transportation_access'],
                'social_isolation_risk' => $validated['social_isolation_risk'],
            ],
        );

        return response()->json(
            $record->load('assessedBy:id,first_name,last_name'),
            201
        );
    }
}
