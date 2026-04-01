<?php

// ─── GrievanceService ─────────────────────────────────────────────────────────
// Manages the grievance workflow per 42 CFR §460.120–§460.121.
//
// CMS timelines enforced:
//   - Urgent: resolve within 72 hours (threat to health/safety)
//   - Standard: resolve within 30 days
//
// Status transitions:
//   open → under_review → resolved
//   open → under_review → escalated
//   any active → withdrawn
//
// alert targets: qa_compliance + it_admin (for urgent grievances)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use LogicException;

class GrievanceService
{
    // ── Valid status transitions ───────────────────────────────────────────────

    /**
     * Allowed status transitions.
     * Withdrawal can happen from any active status.
     * 'withdrawn' and 'resolved' are terminal.
     */
    private const VALID_TRANSITIONS = [
        'open'         => ['under_review', 'withdrawn'],
        'under_review' => ['resolved', 'escalated', 'withdrawn'],
        'escalated'    => ['under_review', 'resolved', 'withdrawn'],
        'resolved'     => [],   // terminal
        'withdrawn'    => [],   // terminal
    ];

    // ── Core workflow methods ──────────────────────────────────────────────────

    /**
     * Open a new grievance on behalf of a participant.
     *
     * If priority=urgent: creates a critical alert targeting qa_compliance + it_admin
     * so on-call staff are notified immediately per §460.120(c).
     *
     * @param  Participant  $participant
     * @param  array        $data   Validated from StoreGrievanceRequest
     * @param  User         $actor  Staff member logging the grievance
     * @return Grievance
     */
    public function open(Participant $participant, array $data, User $actor): Grievance
    {
        $grievance = Grievance::create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'site_id'             => $participant->site_id,
            'filed_by_name'       => $data['filed_by_name'],
            'filed_by_type'       => $data['filed_by_type'],
            'filed_at'            => $data['filed_at'] ?? now(),
            'received_by_user_id' => $actor->id,
            'category'            => $data['category'],
            'description'         => $data['description'],
            'status'              => 'open',
            'priority'            => $data['priority'] ?? 'standard',
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'cms_reportable'      => $data['cms_reportable'] ?? false,
        ]);

        AuditLog::record(
            action:       'grievance.opened',
            tenantId:     $participant->tenant_id,
            userId:       $actor->id,
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  "{$grievance->referenceNumber()} opened for participant #{$participant->id}. "
                . "Category: {$grievance->category}. Priority: {$grievance->priority}.",
        );

        // Urgent grievances require immediate notification to QA + IT Admin
        // per CMS §460.120(c) — 72-hour resolution clock starts now.
        // If a compliance_officer designation holder exists, name them in the alert.
        if ($grievance->priority === 'urgent') {
            $complianceOfficer = User::where('tenant_id', $participant->tenant_id)
                ->withDesignation('compliance_officer')
                ->where('is_active', true)
                ->first();

            $officerNote = $complianceOfficer
                ? " Compliance Officer: {$complianceOfficer->first_name} {$complianceOfficer->last_name}."
                : '';

            Alert::create([
                'tenant_id'          => $participant->tenant_id,
                'alert_type'         => 'grievance_urgent',
                'title'              => "Urgent Grievance: {$grievance->categoryLabel()}",
                'message'            => "Urgent grievance filed for {$participant->first_name} {$participant->last_name}. "
                    . "Must be resolved within 72 hours per CMS §460.120(c).{$officerNote}",
                'severity'           => 'critical',
                'source_module'      => 'grievances',
                'target_departments' => json_encode(['qa_compliance', 'it_admin']),
                'metadata'           => json_encode([
                    'grievance_id'          => $grievance->id,
                    'compliance_officer_id' => $complianceOfficer?->id,
                ]),
            ]);
        }

        return $grievance;
    }

    /**
     * Update the status of a grievance, enforcing valid transitions.
     * Resolving requires resolution_text + resolution_date.
     * Escalating requires escalation_reason.
     *
     * @throws LogicException on invalid transitions or missing required fields
     */
    public function updateStatus(Grievance $grievance, string $newStatus, array $data, User $actor): void
    {
        $allowed = self::VALID_TRANSITIONS[$grievance->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            $hint = ($newStatus === 'escalated' && $grievance->status === 'open')
                ? ' Grievances must be marked Under Review before they can be escalated.'
                : '';
            throw new LogicException(
                "Cannot transition grievance from '{$grievance->status}' to '{$newStatus}'." . $hint
            );
        }

        if ($newStatus === 'resolved') {
            if (empty($data['resolution_text']) || empty($data['resolution_date'])) {
                throw new LogicException('Resolution requires resolution_text and resolution_date.');
            }
        }

        if ($newStatus === 'escalated' && empty($data['escalation_reason'])) {
            throw new LogicException('Escalation requires escalation_reason.');
        }

        $updates = array_merge(['status' => $newStatus], array_filter([
            'resolution_text'       => $data['resolution_text'] ?? null,
            'resolution_date'       => $data['resolution_date'] ?? null,
            'escalation_reason'     => $data['escalation_reason'] ?? null,
            'escalated_to_user_id'  => $data['escalated_to_user_id'] ?? null,
            'investigation_notes'   => $data['investigation_notes'] ?? null,
        ], fn($v) => !is_null($v)));

        $grievance->update($updates);

        AuditLog::record(
            action:       'grievance.status_changed',
            tenantId:     $grievance->tenant_id,
            userId:       $actor->id,
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  "Grievance status changed from '{$grievance->getOriginal('status')}' to '{$newStatus}'.",
            newValues:    ['status' => $newStatus],
        );

        // When escalating: create a targeted alert to the named assignee (if set),
        // plus a fallback to the compliance_officer designation and qa_compliance dept.
        // CMS surveys require a named reviewer in the escalation chain.
        if ($newStatus === 'escalated') {
            $this->createEscalationAlert($grievance, $data, $actor);
        }
    }

    /**
     * Create a targeted alert when a grievance is escalated.
     *
     * If a specific escalated_to_user_id was provided, the alert message names
     * that person and targets their department. A compliance_officer designation
     * holder is also looked up as a fallback reference.
     *
     * CMS surveys ask "who reviewed this escalated grievance?" — this satisfies
     * the named accountability requirement per 42 CFR §460.120.
     */
    private function createEscalationAlert(Grievance $grievance, array $data, User $actor): void
    {
        $assignedUser = null;
        if (!empty($data['escalated_to_user_id'])) {
            $assignedUser = User::find($data['escalated_to_user_id']);
        }

        // Fallback: find the compliance officer if no specific user was named
        if (!$assignedUser) {
            $assignedUser = User::where('tenant_id', $grievance->tenant_id)
                ->withDesignation('compliance_officer')
                ->where('is_active', true)
                ->first();
        }

        $assigneeLine = $assignedUser
            ? " Assigned to: {$assignedUser->first_name} {$assignedUser->last_name}."
            : '';

        Alert::create([
            'tenant_id'          => $grievance->tenant_id,
            'alert_type'         => 'grievance_escalated',
            'title'              => "{$grievance->referenceNumber()} Escalated",
            'message'            => "{$grievance->referenceNumber()} has been escalated.{$assigneeLine} "
                . "Reason: {$grievance->escalation_reason}",
            'severity'           => 'critical',
            'source_module'      => 'grievances',
            'target_departments' => json_encode(['qa_compliance', 'it_admin']),
            'metadata'           => json_encode([
                'grievance_id'         => $grievance->id,
                'escalated_to_user_id' => $assignedUser?->id,
            ]),
        ]);
    }

    /**
     * Set or clear the CMS reportable flag on a grievance.
     *
     * CMS-reportable grievances must be tracked and reported to CMS under
     * 42 CFR §460.120. Qualifying criteria include: discrimination/civil rights
     * violations, abuse/neglect/exploitation allegations, serious safety events,
     * and disenrollment disputes. QA admin makes this determination.
     *
     * Un-flagging also clears cms_reported_at since an unreported grievance
     * cannot have an outstanding CMS submission timestamp.
     */
    public function setCmsReportable(Grievance $grievance, bool $reportable, User $actor): void
    {
        $grievance->update([
            'cms_reportable'  => $reportable,
            'cms_reported_at' => $reportable ? $grievance->cms_reported_at : null,
        ]);

        AuditLog::record(
            action:       $reportable ? 'grievance.cms_reportable_set' : 'grievance.cms_reportable_cleared',
            tenantId:     $grievance->tenant_id,
            userId:       $actor->id,
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  $reportable
                ? "{$grievance->referenceNumber()} flagged as CMS reportable by {$actor->first_name} {$actor->last_name}."
                : "{$grievance->referenceNumber()} CMS reportable flag removed by {$actor->first_name} {$actor->last_name}.",
            newValues:    ['cms_reportable' => $reportable],
        );
    }

    /**
     * Record that the grievance has been submitted to CMS.
     * Sets cms_reported_at timestamp. Only valid if cms_reportable is true.
     * This action is irreversible — once reported to CMS, the timestamp stands.
     *
     * @throws LogicException if grievance is not flagged as CMS reportable
     */
    public function markCmsReported(Grievance $grievance, User $actor): void
    {
        if (! $grievance->cms_reportable) {
            throw new LogicException('Grievance must be flagged as CMS reportable before marking as reported.');
        }

        if ($grievance->cms_reported_at) {
            throw new LogicException('Grievance has already been marked as reported to CMS.');
        }

        $reportedAt = now();
        $grievance->update(['cms_reported_at' => $reportedAt]);

        AuditLog::record(
            action:       'grievance.cms_reported',
            tenantId:     $grievance->tenant_id,
            userId:       $actor->id,
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  "{$grievance->referenceNumber()} marked as submitted to CMS by {$actor->first_name} {$actor->last_name}.",
            newValues:    ['cms_reported_at' => $reportedAt->toIso8601String()],
        );
    }

    /**
     * Record that the participant was notified of the grievance outcome.
     * CMS §460.120(d) requires participant notification of resolution.
     */
    public function notifyParticipant(Grievance $grievance, string $method, User $actor): void
    {
        $grievance->update([
            'participant_notified_at' => now(),
            'notification_method'     => $method,
        ]);

        AuditLog::record(
            action:       'grievance.participant_notified',
            tenantId:     $grievance->tenant_id,
            userId:       $actor->id,
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  "Participant notified of grievance outcome via {$method}.",
        );
    }

    /**
     * Check for overdue grievances across a tenant and create alerts.
     * Called by GrievanceOverdueJob daily at 8am.
     *
     * Rules per CMS §460.120:
     *   - Urgent unresolved >72h → critical alert
     *   - Standard unresolved >30d → escalate to urgent + warning alert
     *
     * @return array{urgent: int, standard: int} counts of alerts created
     */
    public function checkOverdue(int $tenantId): array
    {
        $urgentCount   = 0;
        $standardCount = 0;

        // Look up compliance officer once per tenant for the overdue alert messages
        $complianceOfficer = User::where('tenant_id', $tenantId)
            ->withDesignation('compliance_officer')
            ->where('is_active', true)
            ->first();

        $officerNote = $complianceOfficer
            ? " Compliance Officer on file: {$complianceOfficer->first_name} {$complianceOfficer->last_name}."
            : '';

        // ── Urgent overdue (>72h) → critical alert ─────────────────────────
        $urgentOverdue = Grievance::forTenant($tenantId)->urgentOverdue()->get();
        foreach ($urgentOverdue as $grievance) {
            $hoursOverdue = abs((int) now()->diffInHours($grievance->filed_at)) - Grievance::URGENT_RESOLUTION_HOURS;

            Alert::create([
                'tenant_id'          => $tenantId,
                'alert_type'         => 'grievance_urgent_overdue',
                'title'              => 'Urgent Grievance Overdue',
                'message'            => "Urgent grievance {$grievance->referenceNumber()} for participant #{$grievance->participant_id} "
                    . "has been open for {$hoursOverdue}h beyond the 72-hour CMS resolution requirement.{$officerNote}",
                'severity'           => 'critical',
                'source_module'      => 'grievances',
                'target_departments' => json_encode(['qa_compliance', 'it_admin']),
                'metadata'           => json_encode([
                    'grievance_id'          => $grievance->id,
                    'compliance_officer_id' => $complianceOfficer?->id,
                ]),
            ]);

            $urgentCount++;
        }

        // ── Standard overdue (>30d) → escalate priority + warning alert ───
        $standardOverdue = Grievance::forTenant($tenantId)->standardOverdue()->get();
        foreach ($standardOverdue as $grievance) {
            // Escalate priority so it shows in urgent queue
            $grievance->update(['priority' => 'urgent']);

            $daysOverdue = abs((int) now()->diffInDays($grievance->filed_at)) - Grievance::STANDARD_RESOLUTION_DAYS;

            Alert::create([
                'tenant_id'          => $tenantId,
                'alert_type'         => 'grievance_standard_overdue',
                'title'              => 'Standard Grievance Overdue',
                'message'            => "Grievance {$grievance->referenceNumber()} for participant #{$grievance->participant_id} "
                    . "has been open for {$daysOverdue} days beyond the 30-day resolution requirement.{$officerNote}",
                'severity'           => 'warning',
                'source_module'      => 'grievances',
                'target_departments' => json_encode(['qa_compliance', 'it_admin']),
                'metadata'           => json_encode([
                    'grievance_id'          => $grievance->id,
                    'compliance_officer_id' => $complianceOfficer?->id,
                ]),
            ]);

            $standardCount++;
        }

        return ['urgent' => $urgentCount, 'standard' => $standardCount];
    }
}
