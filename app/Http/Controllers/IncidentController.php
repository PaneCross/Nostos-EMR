<?php

// ─── IncidentController ────────────────────────────────────────────────────────
// Manages PACE adverse event / safety incident records.
//
// Route list:
//   POST /qa/incidents                    → store()   (any authenticated user)
//   GET  /qa/incidents                    → index()   (QA team + all depts)
//   GET  /qa/incidents/{incident}         → show()    (tenant-scoped)
//   PUT  /qa/incidents/{incident}         → update()  (QA Admin only)
//   POST /qa/incidents/{incident}/rca     → rca()     (QA + primary_care)
//   POST /qa/incidents/{incident}/close   → close()   (QA Admin only)
//
// Authorization:
//   - Any authenticated user can create an incident report.
//   - Viewing is tenant-scoped (all staff can view their tenant's incidents).
//   - Editing, RCA, and closing are restricted by department.
//
// Tenant isolation: all queries are scoped to the authenticated user's tenant_id.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\SubmitRcaRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Participant;
use App\Services\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentService $incidentService,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Return all incidents for the authenticated user's tenant.
     * Ordered by occurred_at descending; includes participant + reporter.
     *
     * GET /qa/incidents
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $statusFilter = $request->query('status');

        $query = Incident::forTenant($tenantId)
            ->with(['participant:id,mrn,first_name,last_name', 'reportedBy:id,first_name,last_name'])
            ->orderBy('occurred_at', 'desc');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        return response()->json($query->paginate(50));
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new incident report for a participant.
     * rca_required is auto-set based on incident_type (CMS 42 CFR 460.136).
     *
     * POST /qa/incidents
     */
    public function store(StoreIncidentRequest $request): JsonResponse
    {
        $participantId = $request->validated('participant_id');
        $participant   = Participant::findOrFail($participantId);

        abort_if(
            $participant->tenant_id !== $request->user()->tenant_id,
            403,
            'Participant belongs to a different organization.',
        );

        $incident = $this->incidentService->createIncident(
            $participant,
            $request->validated(),
            $request->user(),
        );

        return response()->json(
            $incident->load(['participant:id,mrn,first_name,last_name', 'reportedBy:id,first_name,last_name']),
            201,
        );
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * Return a single incident with all relationships.
     *
     * GET /qa/incidents/{incident}
     */
    public function show(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizeTenant($incident, $request->user());

        AuditLog::record(
            action: 'qa.incident.viewed',
            tenantId: $incident->tenant_id,
            userId: $request->user()->id,
            resourceType: 'incident',
            resourceId: $incident->id,
        );

        return response()->json(
            $incident->load(['participant', 'reportedBy', 'rcaCompletedBy'])
        );
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update non-status fields on an incident. QA Admin only.
     * Status changes use the /close endpoint; RCA uses /rca endpoint.
     *
     * PUT /qa/incidents/{incident}
     */
    public function update(UpdateIncidentRequest $request, Incident $incident): JsonResponse
    {
        $this->authorizeTenant($incident, $request->user());

        if ($incident->isClosed()) {
            return response()->json(['message' => 'Cannot edit a closed incident.'], 409);
        }

        $incident->update($request->validated());

        return response()->json($incident->fresh()->load(['participant:id,mrn,first_name,last_name']), 200);
    }

    // ── RCA ───────────────────────────────────────────────────────────────────

    /**
     * Submit the completed Root Cause Analysis text for an incident.
     * Marks rca_completed=true and advances status to 'under_review'.
     *
     * POST /qa/incidents/{incident}/rca
     */
    public function rca(SubmitRcaRequest $request, Incident $incident): JsonResponse
    {
        $this->authorizeTenant($incident, $request->user());

        try {
            $this->incidentService->submitRca(
                $incident,
                $request->validated('rca_text'),
                $request->user(),
            );
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($incident->fresh(), 200);
    }

    // ── Close ─────────────────────────────────────────────────────────────────

    /**
     * Close an incident. Blocked if RCA is required but not completed.
     * Only QA compliance or IT admin may close incidents.
     *
     * POST /qa/incidents/{incident}/close
     */
    public function close(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizeTenant($incident, $request->user());
        $this->authorizeQaDept($request->user());

        try {
            $this->incidentService->closeIncident($incident, $request->user());
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($incident->fresh(), 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Abort 403 if the incident belongs to a different tenant. */
    private function authorizeTenant(Incident $incident, $user): void
    {
        abort_if(
            $incident->tenant_id !== $user->tenant_id,
            403,
            'Access denied: incident belongs to a different organization.',
        );
    }

    /** Abort 403 if user is not in qa_compliance or it_admin department. */
    private function authorizeQaDept($user): void
    {
        abort_if(
            ! in_array($user->department, ['qa_compliance', 'it_admin'], true),
            403,
            'Only QA Compliance or IT Admin may perform this action.',
        );
    }
}
