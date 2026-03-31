<?php

// ─── ConsentController ────────────────────────────────────────────────────────
// JSON API for participant consent and acknowledgment records.
// Used by the Consents tab in Participants/Show.tsx.
//
// HIPAA 45 CFR §164.520: Providers must make good-faith effort to obtain
// written acknowledgment of NPP receipt at first service delivery.
// NostosEMR auto-creates a pending npp_acknowledgment record at enrollment;
// this controller handles recording the actual acknowledgment.
//
// Routes (all under /participants/{participant}/):
//   GET  /consents          → index (list all consents for participant)
//   POST /consents          → store (create consent record)
//   PUT  /consents/{cid}    → update (record acknowledgment / update status)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreConsentRequest;
use App\Http\Requests\UpdateConsentRequest;
use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ConsentController extends Controller
{
    // ── Authorization ─────────────────────────────────────────────────────────

    /** Abort 403 if participant does not belong to the user's tenant */
    private function authorizeParticipant(Participant $participant): void
    {
        abort_if($participant->tenant_id !== Auth::user()->tenant_id, 403);
    }

    /** Abort 403 if consent does not belong to the given participant */
    private function authorizeConsent(ConsentRecord $consent, Participant $participant): void
    {
        abort_if($consent->participant_id !== $participant->id, 403);
        abort_if($consent->tenant_id !== Auth::user()->tenant_id, 403);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * List all consent records for a participant.
     * Returns JSON for the Consents tab in Participants/Show.tsx.
     */
    public function index(Participant $participant): JsonResponse
    {
        $this->authorizeParticipant($participant);

        $consents = ConsentRecord::forParticipant($participant->id)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'consents' => $consents->map->toApiArray()->values(),
        ]);
    }

    /**
     * Create a new consent record for a participant.
     * Typically used to add ad-hoc consent types beyond the auto-created NPP.
     */
    public function store(StoreConsentRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeParticipant($participant);

        $consent = ConsentRecord::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'created_by_user_id'  => Auth::id(),
        ]));

        AuditLog::record(
            action:       'consent.created',
            tenantId:     $participant->tenant_id,
            userId:       Auth::id(),
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Consent record '{$consent->consent_type}' created for participant #{$participant->id}.",
        );

        return response()->json(['consent' => $consent->fresh()->toApiArray()], 201);
    }

    /**
     * Update a consent record — typically to record acknowledgment or refusal.
     * Requires enrollment, qa_compliance, or it_admin department.
     */
    public function update(UpdateConsentRequest $request, Participant $participant, ConsentRecord $consent): JsonResponse
    {
        $this->authorizeParticipant($participant);
        $this->authorizeConsent($consent, $participant);

        // Only enrollment, QA admin, or IT admin may update consent records
        $dept = Auth::user()->department;
        abort_unless(
            in_array($dept, ['enrollment', 'qa_compliance', 'it_admin']) || Auth::user()->isSuperAdmin(),
            403
        );

        $consent->update($request->validated());

        AuditLog::record(
            action:       'consent.updated',
            tenantId:     $participant->tenant_id,
            userId:       Auth::id(),
            resourceType: 'consent_record',
            resourceId:   $consent->id,
            description:  "Consent record '{$consent->consent_type}' updated to status '{$consent->status}'.",
        );

        return response()->json(['consent' => $consent->fresh()->toApiArray()]);
    }
}
