<?php

// ─── ProcedureController ──────────────────────────────────────────────────────
// Manages procedure history for PACE participants (USCDI v3 Procedures).
// Distinct from encounter_log — stores the full procedure narrative with CPT/SNOMED.
//
// GET  /participants/{id}/procedures      → index()  JSON list (newest first)
// POST /participants/{id}/procedures      → store()  Record new procedure
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Procedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcedureController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/procedures
     * Returns all procedure records newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $procedures = $participant->procedures()
            ->with('performedBy:id,first_name,last_name')
            ->orderByDesc('performed_date')
            ->get();

        AuditLog::record(
            action:       'participant.procedures.viewed',
            tenantId:     $request->user()->tenant_id,
            userId:       $request->user()->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Procedure history viewed for {$participant->mrn}",
        );

        return response()->json($procedures);
    }

    /**
     * POST /participants/{participant}/procedures
     * Records a new procedure (internal, external report, or patient-reported).
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $validated = $request->validate([
            'procedure_name'  => ['required', 'string', 'max:300'],
            'cpt_code'        => ['nullable', 'string', 'max:10'],
            'snomed_code'     => ['nullable', 'string', 'max:20'],
            'performed_date'  => ['required', 'date', 'before_or_equal:today'],
            'facility'        => ['nullable', 'string', 'max:200'],
            'body_site'       => ['nullable', 'string', 'max:100'],
            'outcome'         => ['nullable', 'string', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'source'          => ['required', 'string', 'in:' . implode(',', Procedure::SOURCES)],
        ]);

        $procedure = Procedure::create(array_merge($validated, [
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'performed_by_user_id' => $validated['source'] === 'internal' ? $user->id : null,
        ]));

        AuditLog::record(
            action:       'participant.procedure.recorded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Procedure recorded ({$validated['procedure_name']}) for {$participant->mrn}",
            newValues: [
                'procedure_name' => $validated['procedure_name'],
                'performed_date' => $validated['performed_date'],
                'cpt_code'       => $validated['cpt_code'] ?? null,
                'source'         => $validated['source'],
            ],
        );

        return response()->json(
            $procedure->load('performedBy:id,first_name,last_name'),
            201
        );
    }
}
