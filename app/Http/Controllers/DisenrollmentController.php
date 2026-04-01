<?php

// ─── DisenrollmentController ───────────────────────────────────────────────────
// Manages the disenrollment transition plan and CMS/SMA notification workflow.
// 42 CFR §460.116: PACE must provide a transition plan when a participant disenrolls.
//
// Routes (nested under /participants/{participant}/disenrollment):
//   GET   /  → show()   Returns the participant's most recent DisenrollmentRecord
//   PATCH /  → update() Updates transition plan fields and CMS notification status
//
// Access control:
//   All authenticated users may view (same tenant).
//   Only enrollment, qa_compliance, and it_admin can update.
//
// The DisenrollmentRecord is created automatically by EnrollmentService::disenroll().
// This controller is read/update only — creation is handled by the enrollment workflow.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DisenrollmentRecord;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DisenrollmentController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /** Only enrollment, qa_compliance, and it_admin may update disenrollment records. */
    private function authorizeUpdate($user): void
    {
        abort_unless(
            in_array($user->department, ['enrollment', 'qa_compliance', 'it_admin'], true)
            || $user->isSuperAdmin(),
            403,
            'Only Enrollment, QA, or IT Admin may update disenrollment records.'
        );
    }

    /**
     * GET /participants/{participant}/disenrollment
     * Returns the most recent disenrollment record for the participant.
     * Returns null (204) if the participant has no disenrollment record yet.
     */
    public function show(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $record = DisenrollmentRecord::forTenant($user->tenant_id)
            ->where('participant_id', $participant->id)
            ->with([
                'createdBy:id,first_name,last_name',
                'transitionPlanCompletedBy:id,first_name,last_name',
                'cmsNotifiedBy:id,first_name,last_name',
            ])
            ->latest()
            ->first();

        if (! $record) {
            return response()->json(null, 204);
        }

        AuditLog::record(
            action:       'participant.disenrollment.viewed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Disenrollment record viewed for {$participant->mrn}",
        );

        return response()->json([
            'id'                               => $record->id,
            'participant_id'                   => $record->participant_id,
            'reason'                           => $record->reason,
            'effective_date'                   => $record->effective_date?->toDateString(),
            'notes'                            => $record->notes,
            'transition_plan_status'           => $record->transition_plan_status,
            'transition_plan_status_label'     => $record->planStatusLabel(),
            'transition_plan_text'             => $record->transition_plan_text,
            'transition_plan_due_date'         => $record->transition_plan_due_date?->toDateString(),
            'transition_plan_completed_date'   => $record->transition_plan_completed_date?->toDateString(),
            'transition_plan_completed_by'     => $record->transitionPlanCompletedBy
                ? $record->transitionPlanCompletedBy->first_name . ' ' . $record->transitionPlanCompletedBy->last_name
                : null,
            'cms_notification_required'        => $record->cms_notification_required,
            'cms_notification_pending'         => $record->cmsNotificationPending(),
            'cms_notified_at'                  => $record->cms_notified_at?->toIso8601String(),
            'cms_notified_by'                  => $record->cmsNotifiedBy
                ? $record->cmsNotifiedBy->first_name . ' ' . $record->cmsNotifiedBy->last_name
                : null,
            'cms_notification_notes'           => $record->cms_notification_notes,
            'providers_notified'               => $record->providers_notified,
            'providers_notified_at'            => $record->providers_notified_at?->toIso8601String(),
            'transition_plan_overdue'          => $record->transitionPlanOverdue(),
            'created_by'                       => $record->createdBy
                ? $record->createdBy->first_name . ' ' . $record->createdBy->last_name
                : null,
            'created_at'                       => $record->created_at?->toIso8601String(),
        ]);
    }

    /**
     * PATCH /participants/{participant}/disenrollment
     * Updates transition plan status/text and CMS notification tracking.
     * Restricted to enrollment, qa_compliance, and it_admin departments.
     */
    public function update(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeUpdate($user);

        $record = DisenrollmentRecord::forTenant($user->tenant_id)
            ->where('participant_id', $participant->id)
            ->latest()
            ->firstOrFail();

        $validated = $request->validate([
            'transition_plan_status'         => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'not_required'])],
            'transition_plan_text'           => ['sometimes', 'nullable', 'string'],
            'transition_plan_completed_date' => ['sometimes', 'nullable', 'date'],
            'cms_notification_required'      => ['sometimes', 'boolean'],
            'cms_notified_at'               => ['sometimes', 'nullable', 'date'],
            'cms_notification_notes'         => ['sometimes', 'nullable', 'string'],
            'providers_notified'             => ['sometimes', 'boolean'],
            'providers_notified_at'          => ['sometimes', 'nullable', 'date'],
            'notes'                          => ['sometimes', 'nullable', 'string'],
        ]);

        $updateData = $validated;

        // Auto-set completion metadata when marking completed
        if (isset($validated['transition_plan_status']) && $validated['transition_plan_status'] === 'completed') {
            $updateData['transition_plan_completed_date'] ??= now()->toDateString();
            $updateData['transition_plan_completed_by_user_id'] = $user->id;
        }

        // Auto-set cms_notified_by when recording notification
        if (isset($validated['cms_notified_at']) && ! is_null($validated['cms_notified_at'])) {
            $updateData['cms_notified_by_user_id'] = $user->id;
        }

        // Auto-set providers_notified_at when marking providers notified
        if (isset($validated['providers_notified']) && $validated['providers_notified'] === true) {
            $updateData['providers_notified_at'] = now()->toIso8601String();
        }

        $record->update($updateData);

        AuditLog::record(
            action:       'participant.disenrollment.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Disenrollment record updated for {$participant->mrn}",
            newValues:    $validated,
        );

        return response()->json(['message' => 'Disenrollment record updated.', 'record_id' => $record->id]);
    }
}
