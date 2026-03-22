<?php

// ─── ImmunizationController ───────────────────────────────────────────────────
// Manages vaccine administration records for PACE participants.
// Supports HPMS quality reporting (flu/pneumo rates) and FHIR R4 Immunization.
//
// GET  /participants/{id}/immunizations       → index()   JSON list
// POST /participants/{id}/immunizations       → store()   Record administration or refusal
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Immunization;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImmunizationController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/immunizations
     * Returns all immunization records ordered by date descending.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $immunizations = $participant->immunizations()
            ->with('administeredBy:id,first_name,last_name')
            ->orderByDesc('administered_date')
            ->get();

        AuditLog::record(
            action:       'participant.immunizations.viewed',
            tenantId:     $request->user()->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Immunization record viewed for {$participant->mrn}",
        );

        return response()->json($immunizations);
    }

    /**
     * POST /participants/{participant}/immunizations
     * Records a vaccine administration or a patient refusal.
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $validated = $request->validate([
            'vaccine_type'             => ['required', 'string', 'in:' . implode(',', Immunization::VACCINE_TYPES)],
            'vaccine_name'             => ['required', 'string', 'max:200'],
            'cvx_code'                 => ['nullable', 'string', 'max:10'],
            'administered_date'        => ['required', 'date', 'before_or_equal:today'],
            'administered_at_location' => ['nullable', 'string', 'max:200'],
            'lot_number'               => ['nullable', 'string', 'max:50'],
            'manufacturer'             => ['nullable', 'string', 'max:100'],
            'dose_number'              => ['nullable', 'integer', 'min:1', 'max:10'],
            'next_dose_due'            => ['nullable', 'date', 'after:administered_date'],
            'refused'                  => ['boolean'],
            'refusal_reason'           => ['nullable', 'required_if:refused,true', 'string', 'max:500'],
        ]);

        $immunization = Immunization::create(array_merge($validated, [
            'participant_id'          => $participant->id,
            'tenant_id'               => $user->tenant_id,
            'administered_by_user_id' => $user->id,
        ]));

        AuditLog::record(
            action:       'participant.immunization.recorded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  ($validated['refused'] ?? false)
                ? "Immunization refused ({$validated['vaccine_type']}) for {$participant->mrn}"
                : "Immunization recorded ({$validated['vaccine_type']}) for {$participant->mrn}",
            newValues: [
                'vaccine_type'      => $validated['vaccine_type'],
                'administered_date' => $validated['administered_date'],
                'refused'           => $validated['refused'] ?? false,
            ],
        );

        return response()->json(
            $immunization->load('administeredBy:id,first_name,last_name'),
            201
        );
    }
}
