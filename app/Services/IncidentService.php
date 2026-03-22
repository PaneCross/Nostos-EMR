<?php

// ─── IncidentService ───────────────────────────────────────────────────────────
// Business logic for incident reporting and RCA workflow.
//
// Core responsibilities:
//   - createIncident(): auto-sets rca_required based on incident_type
//   - updateStatus(): validates allowed transitions
//   - submitRca(): records RCA text + marks rca_completed
//   - closeIncident(): blocks closure if RCA is required but not completed
//
// CMS Rule (42 CFR 460.136):
//   Root cause analysis is required for: falls, medication errors, elopements,
//   hospitalizations, ER visits, and abuse/neglect incidents.
//   This is enforced here, never overridable from the UI.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\User;
use LogicException;

class IncidentService
{
    /**
     * Create a new incident record.
     * Automatically sets rca_required = true for CMS-mandated incident types.
     * Sets status = 'open' and reported_at = now() if not provided.
     */
    public function createIncident(
        Participant $participant,
        array $data,
        User $user,
    ): Incident {
        $incidentType = $data['incident_type'];

        // CMS 42 CFR 460.136: RCA mandatory for specified high-severity types
        $rcaRequired = in_array($incidentType, Incident::RCA_REQUIRED_TYPES, true);

        $incident = Incident::create([
            ...$data,
            'tenant_id'          => $participant->tenant_id,
            'participant_id'     => $participant->id,
            'reported_by_user_id'=> $user->id,
            'reported_at'        => $data['reported_at'] ?? now(),
            'rca_required'       => $rcaRequired,
            'rca_completed'      => false,
            'status'             => 'open',
        ]);

        AuditLog::record(
            action: 'qa.incident.created',
            tenantId: $incident->tenant_id,
            userId: $user->id,
            resourceType: 'incident',
            resourceId: $incident->id,
            description: "Incident reported: {$incident->typeLabel()} for participant #{$participant->id}" .
                ($rcaRequired ? ' [RCA required]' : ''),
        );

        return $incident;
    }

    /**
     * Update the incident's status.
     * Validates allowed transitions.
     * 'closed' requires canClose() — blocks if RCA is pending.
     *
     * @throws LogicException if the transition is invalid.
     */
    public function updateStatus(Incident $incident, string $newStatus, User $user): void
    {
        if ($incident->isClosed()) {
            throw new LogicException('Cannot change status of a closed incident.');
        }

        if ($newStatus === 'closed' && ! $incident->canClose()) {
            throw new LogicException(
                'Cannot close this incident: RCA is required but not yet completed.'
            );
        }

        $old = $incident->status;
        $incident->update(['status' => $newStatus]);

        AuditLog::record(
            action: 'qa.incident.status_changed',
            tenantId: $incident->tenant_id,
            userId: $user->id,
            resourceType: 'incident',
            resourceId: $incident->id,
            description: "Incident status changed: '{$old}' → '{$newStatus}'",
        );
    }

    /**
     * Record the completed RCA for an incident.
     * Marks rca_completed = true and advances status to 'rca_in_progress' → 'under_review'.
     *
     * @throws LogicException if RCA was not required or incident is closed.
     */
    public function submitRca(Incident $incident, string $rcaText, User $user): void
    {
        if ($incident->isClosed()) {
            throw new LogicException('Cannot submit RCA on a closed incident.');
        }

        $incident->update([
            'rca_text'                 => $rcaText,
            'rca_completed'            => true,
            'rca_completed_by_user_id' => $user->id,
            'status'                   => 'under_review', // RCA done, awaiting final QA review
        ]);

        AuditLog::record(
            action: 'qa.incident.rca_submitted',
            tenantId: $incident->tenant_id,
            userId: $user->id,
            resourceType: 'incident',
            resourceId: $incident->id,
            description: "RCA submitted for incident #{$incident->id}",
        );
    }

    /**
     * Close the incident after all requirements are met.
     * Convenience wrapper around updateStatus('closed').
     *
     * @throws LogicException if closure conditions are not met.
     */
    public function closeIncident(Incident $incident, User $user): void
    {
        $this->updateStatus($incident, 'closed', $user);
    }
}
