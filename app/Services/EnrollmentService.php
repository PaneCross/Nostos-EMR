<?php

// ─── EnrollmentService ────────────────────────────────────────────────────────
// Enforces the CMS PACE enrollment state machine and handles side effects.
//
// State machine:
//   new → intake_scheduled → intake_in_progress → intake_complete
//     → eligibility_pending → pending_enrollment → enrolled
//   OR: any non-terminal state → declined / withdrawn
//
// All transitions are validated against VALID_TRANSITIONS. Invalid transitions
// throw InvalidStateTransitionException (maps to HTTP 422 in controller).
//
// Side effects on transition to 'enrolled':
//   1. Create emr_participants record if not already linked
//   2. Set participant.enrollment_status = 'enrolled', enrollment_date = today
//   3. Log action='participant.enrolled' in audit_log
//
// Side effects on transition to 'declined' / 'withdrawn':
//   - Audit log entry with reason
//
// Disenrollment (separate from referral workflow):
//   POST /participants/{id}/disenroll → sets enrollment_status,
//   disenrollment_date, disenrollment_reason on emr_participants.
//   W4-5: also creates DisenrollmentRecord (42 CFR §460.116 transition plan) and
//   up to 2 SDRs (social_work for transition plan, enrollment for CMS notification).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\DisenrollmentRecord;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Sdr;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EnrollmentService
{

    // ── State machine ─────────────────────────────────────────────────────────

    /**
     * Valid state transitions. Key = current status, value = allowed next statuses.
     * The forward path is sequential; any state can exit to declined or withdrawn
     * (except already-terminal states).
     *
     * CMS requirement: eligibility must be confirmed before enrollment is offered.
     */
    public const VALID_TRANSITIONS = [
        'new'                 => ['intake_scheduled', 'declined', 'withdrawn'],
        'intake_scheduled'    => ['intake_in_progress', 'declined', 'withdrawn'],
        'intake_in_progress'  => ['intake_complete', 'declined', 'withdrawn'],
        'intake_complete'     => ['eligibility_pending', 'declined', 'withdrawn'],
        'eligibility_pending' => ['pending_enrollment', 'declined', 'withdrawn'],
        'pending_enrollment'  => ['enrolled', 'declined', 'withdrawn'],
        // Terminal states — no outbound transitions
        'enrolled'            => [],
        'declined'            => [],
        'withdrawn'           => [],
    ];

    /**
     * Transition a referral to a new status, enforcing the state machine.
     * Throws InvalidStateTransitionException on invalid transitions.
     * Fires enrollment side effects if transitioning to 'enrolled'.
     *
     * @param  Referral  $referral
     * @param  string    $newStatus  Must be in VALID_TRANSITIONS[$referral->status]
     * @param  User      $user       Performing the transition (for audit)
     * @param  array     $extra      Optional extra fields: notes, decline_reason, withdrawn_reason
     * @throws InvalidStateTransitionException
     */
    public function transition(Referral $referral, string $newStatus, User $user, array $extra = []): void
    {
        $allowed = self::VALID_TRANSITIONS[$referral->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidStateTransitionException($referral->status, $newStatus);
        }

        $updates = ['status' => $newStatus];

        // Capture reason for terminal exit states
        if ($newStatus === 'declined' && isset($extra['decline_reason'])) {
            $updates['decline_reason'] = $extra['decline_reason'];
        }
        if ($newStatus === 'withdrawn' && isset($extra['withdrawn_reason'])) {
            $updates['withdrawn_reason'] = $extra['withdrawn_reason'];
        }
        if (isset($extra['notes'])) {
            $updates['notes'] = $extra['notes'];
        }

        $referral->update($updates);

        // Audit every status transition
        AuditLog::record(
            action: "enrollment.referral.status_changed",
            tenantId: $referral->tenant_id,
            userId: $user->id,
            resourceType: 'referral',
            resourceId: $referral->id,
            description: "Referral status changed from '{$referral->getOriginal('status')}' to '{$newStatus}'",
        );

        // ── Side effects for enrollment ────────────────────────────────────
        if ($newStatus === 'enrolled') {
            $this->handleEnrollment($referral, $user);
        }

        // ── Side effects for declined/withdrawn ────────────────────────────
        if (in_array($newStatus, ['declined', 'withdrawn'], true)) {
            Log::info("Referral {$referral->id} {$newStatus}", [
                'tenant_id'    => $referral->tenant_id,
                'referral_id'  => $referral->id,
                'reason'       => $extra['decline_reason'] ?? $extra['withdrawn_reason'] ?? null,
            ]);
        }
    }

    /**
     * Link a participant record to the referral.
     * Called when clinician clicks 'Create Participant Record' at intake_complete.
     * Expects $referral->status === 'intake_complete' (enforced in controller).
     *
     * @param  Referral     $referral
     * @param  Participant  $participant  Existing participant to link
     * @param  User         $user         For audit
     */
    public function linkParticipant(Referral $referral, Participant $participant, User $user): void
    {
        $referral->update(['participant_id' => $participant->id]);

        AuditLog::record(
            action: 'enrollment.referral.participant_linked',
            tenantId: $referral->tenant_id,
            userId: $user->id,
            resourceType: 'referral',
            resourceId: $referral->id,
            description: "Participant #{$participant->id} ({$participant->mrn}) linked to referral #{$referral->id}",
        );
    }

    // ── Disenrollment ─────────────────────────────────────────────────────────

    /**
     * Disenroll a currently-enrolled participant.
     * Updates enrollment_status, disenrollment_date, disenrollment_reason on the
     * participant record.
     *
     * If cms_notification_required = true, a placeholder audit entry is created
     * to flag the HPMS reporting requirement (TODO Phase 6B: create QA task).
     *
     * Valid reasons: voluntary, involuntary, deceased, moved, nf_admission, other.
     *
     * @param  Participant  $participant
     * @param  string       $reason     Disenrollment reason enum
     * @param  string       $effectiveDate  Y-m-d string
     * @param  string|null  $notes
     * @param  bool         $cmsNotificationRequired
     * @param  User         $user
     */
    public function disenroll(
        Participant $participant,
        string $reason,
        string $effectiveDate,
        ?string $notes,
        bool $cmsNotificationRequired,
        User $user,
    ): void {
        $participant->update([
            'enrollment_status'      => 'disenrolled',
            'disenrollment_date'     => $effectiveDate,
            'disenrollment_reason'   => $reason,
            'is_active'              => false,
        ]);

        AuditLog::record(
            action: 'participant.disenrolled',
            tenantId: $participant->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Participant disenrolled. Reason: {$reason}. Effective: {$effectiveDate}." .
                ($notes ? " Notes: {$notes}" : '') .
                ($cmsNotificationRequired ? ' [CMS notification required]' : ''),
        );

        // W4-5: Create DisenrollmentRecord for 42 CFR §460.116 transition plan tracking.
        // Transition plan due = effective_date + 30 days.
        // For deceased participants, transition plan status = 'not_required'.
        $planStatus = $reason === 'deceased' ? DisenrollmentRecord::PLAN_NOT_REQUIRED : DisenrollmentRecord::PLAN_PENDING;
        $effectiveDateCarbon = Carbon::parse($effectiveDate);

        $disRecord = DisenrollmentRecord::create([
            'participant_id'           => $participant->id,
            'tenant_id'                => $participant->tenant_id,
            'created_by_user_id'       => $user->id,
            'reason'                   => $reason,
            'effective_date'           => $effectiveDate,
            'notes'                    => $notes,
            'transition_plan_status'   => $planStatus,
            'transition_plan_due_date' => $reason !== 'deceased'
                ? $effectiveDateCarbon->copy()->addDays(DisenrollmentRecord::TRANSITION_PLAN_DUE_DAYS)->toDateString()
                : null,
            'cms_notification_required' => $cmsNotificationRequired,
        ]);

        // W4-5: Create an SDR for the transition plan (social_work dept must coordinate).
        // request_type = 'other' (only valid ENUM value for custom workflow SDRs).
        Sdr::create([
            'tenant_id'             => $participant->tenant_id,
            'participant_id'        => $participant->id,
            'requesting_user_id'    => $user->id,
            'requesting_department' => 'enrollment',   // enrollment coordinator initiates
            'assigned_department'   => 'social_work',
            'request_type'          => 'other',
            'priority'              => 'urgent',
            'description'           => "Transition plan required: {$participant->first_name} {$participant->last_name}"
                . " disenrolled effective {$effectiveDate}. Reason: {$reason}."
                . " 42 CFR §460.116: transition plan due within 30 days (by {$effectiveDateCarbon->copy()->addDays(30)->toDateString()}).",
        ]);

        // W4-5: If CMS notification is required, also create an SDR for enrollment dept.
        if ($cmsNotificationRequired) {
            Sdr::create([
                'tenant_id'             => $participant->tenant_id,
                'participant_id'        => $participant->id,
                'requesting_user_id'    => $user->id,
                'requesting_department' => 'enrollment',   // enrollment coordinator initiates
                'assigned_department'   => 'enrollment',
                'request_type'          => 'other',
                'priority'              => 'urgent',
                'description'           => "CMS/SMA notification required: {$participant->first_name} {$participant->last_name}"
                    . " disenrolled effective {$effectiveDate}. Reason: {$reason}."
                    . " Submit disenrollment notification to CMS and State Medicaid Agency (SMA)."
                    . " Record completion in the disenrollment record (DisenrollmentRecord #{$disRecord->id}).",
            ]);

            Log::info("CMS notification SDR created for disenrollment of participant #{$participant->id}", [
                'reason'             => $reason,
                'effective_date'     => $effectiveDate,
                'disenrollment_id'   => $disRecord->id,
            ]);
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Handle side effects when a referral transitions to 'enrolled'.
     *
     * 1. If no participant is linked yet, create one from referral data.
     *    (Most enrollments link a participant at intake_complete; this is
     *    a safety net for edge cases where the participant was not pre-created.)
     * 2. Set participant enrollment_status = 'enrolled', enrollment_date = today.
     * 3. Log participant.enrolled action.
     *
     * @param  Referral  $referral  Already updated to status='enrolled'
     * @param  User      $user
     */
    private function handleEnrollment(Referral $referral, User $user): void
    {
        // If no participant has been linked yet, we cannot auto-create one —
        // the full intake form is required. Log and alert.
        if (!$referral->participant_id) {
            Log::error("Referral #{$referral->id} enrolled but no participant record linked.", [
                'referral_id' => $referral->id,
                'tenant_id'   => $referral->tenant_id,
            ]);
            return;
        }

        $participant = Participant::find($referral->participant_id);
        if (!$participant) {
            return;
        }

        $participant->update([
            'enrollment_status'  => 'enrolled',
            'enrollment_date'    => now()->toDateString(),
            'is_active'          => true,
        ]);

        AuditLog::record(
            action: 'participant.enrolled',
            tenantId: $referral->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Participant enrolled via referral #{$referral->id}",
        );

        Log::info("Participant #{$participant->id} enrolled via referral #{$referral->id}", [
            'participant_id' => $participant->id,
            'referral_id'    => $referral->id,
        ]);

        // Auto-create pending NPP acknowledgment consent record (HIPAA 45 CFR §164.520).
        // The participant must acknowledge the Notice of Privacy Practices at or before
        // first service delivery. Created as 'pending' so enrollment staff can track completion.
        // W4-1: BLOCKER-02 resolution — formal consent tracking per 42 CFR §460.110.
        \App\Models\ConsentRecord::create([
            'participant_id'     => $participant->id,
            'tenant_id'          => $referral->tenant_id,
            'consent_type'       => \App\Models\ConsentRecord::NPP_TYPE,
            'document_title'     => 'Notice of Privacy Practices',
            'status'             => 'pending',
            'created_by_user_id' => $user->id,
        ]);

        // TODO Phase 7C: Create participant IDT chat channel
    }
}
