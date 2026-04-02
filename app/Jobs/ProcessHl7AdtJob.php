<?php

// ─── ProcessHl7AdtJob ─────────────────────────────────────────────────────────
// Processes inbound HL7 ADT messages asynchronously.
//
// A01 (Admit):
//   - Looks up participant by MRN
//   - Creates EncounterLog (service_type='other', notes=facility name)
//   - Creates alert for social_work + idt ('Participant hospitalized', severity=warning)
//   - W4-6: Creates SignificantChangeEvent (42 CFR §460.104(b) 30-day IDT reassessment)
//   - Marks integration_log as processed
//
// A03 (Discharge):
//   - Looks up participant by MRN
//   - Creates Sdr (72-hour discharge follow-up rule — CMS PACE requirement)
//   - Puts active care plan into under_review status
//   - Creates alert for idt ('Participant discharged - review meds + care plan', severity=warning)
//   - Marks integration_log as processed
//
// A08 (Update — demographic/encounter data update):
//   - Audit log only — no clinical actions, per PACE protocol
//   - Marks integration_log as processed
//
// Unknown MRN:
//   - Logs warning (participant may not be enrolled yet)
//   - Marks integration_log as failed gracefully (retry possible)
//
// Queue: 'integrations' (separate from transport-webhooks and compliance)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\EncounterLog;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\SignificantChangeEvent;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Support\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessHl7AdtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int    $integrationLogId,
        public readonly array  $payload,
        public readonly int    $tenantId,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(AlertService $alertService): void
    {
        $logEntry    = IntegrationLog::findOrFail($this->integrationLogId);
        $messageType = $this->payload['message_type'] ?? '';
        $mrn         = $this->payload['patient_mrn']  ?? '';

        Log::info('[ProcessHl7AdtJob] Processing ADT message', [
            'integration_log_id' => $this->integrationLogId,
            'message_type'       => $messageType,
            'patient_mrn'        => $mrn,
        ]);

        // A08 — update only: record in audit log, no clinical action needed
        if ($messageType === 'A08') {
            $this->handleA08($logEntry);
            return;
        }

        // Resolve participant by MRN scoped to tenant
        $participant = Participant::where('tenant_id', $this->tenantId)
            ->where('mrn', $mrn)
            ->first();

        if (! $participant) {
            // MRN not found — could be a non-enrolled individual or data entry error.
            // Log warning and mark failed so IT Admin can review and retry.
            Log::warning('[ProcessHl7AdtJob] Unknown MRN — participant not found', [
                'mrn'       => $mrn,
                'tenant_id' => $this->tenantId,
            ]);
            $logEntry->markFailed("Participant not found for MRN: {$mrn}");
            return;
        }

        match ($messageType) {
            'A01' => $this->handleA01($logEntry, $participant, $alertService),
            'A03' => $this->handleA03($logEntry, $participant, $alertService),
            default => $this->handleUnknownType($logEntry, $messageType),
        };
    }

    // ── ADT A01: Hospital Admission ────────────────────────────────────────────

    /**
     * Admission: create encounter log + alert Social Work and IDT.
     * PACE requires tracking all unplanned hospitalizations (42 CFR 460.112).
     * Social Work and IDT are alerted so the full care team is aware.
     */
    private function handleA01(
        IntegrationLog $logEntry,
        Participant    $participant,
        AlertService   $alertService,
    ): void {
        $facility = $this->payload['facility'] ?? 'External Facility';

        // Create encounter record for Finance/QA tracking
        EncounterLog::create([
            'tenant_id'      => $this->tenantId,
            'participant_id' => $participant->id,
            'service_date'   => now()->toDateString(),
            'service_type'   => 'other',
            'notes'          => "HL7 A01 Admission — {$facility}",
        ]);

        // Alert social_work and idt: PACE care coordination requires both departments
        // to be notified immediately when a participant is hospitalized.
        $alertService->create([
            'tenant_id'           => $this->tenantId,
            'participant_id'      => $participant->id,
            'source_module'       => 'integration',
            'alert_type'          => 'hospitalization',
            'title'               => "Participant Hospitalized: {$participant->full_name}",
            'message'             => "{$participant->full_name} (MRN: {$participant->mrn}) has been admitted to {$facility}.",
            'severity'            => 'warning',
            'target_departments'  => ['social_work', 'idt'],
            'created_by_system'   => true,
        ]);

        // W4-6 / GAP-10: Hospitalization triggers 30-day IDT reassessment requirement.
        // 42 CFR §460.104(b): IDT must reassess within 30 days of significant change in status.
        $admissionDate = now()->toDateString();
        SignificantChangeEvent::create([
            'tenant_id'                    => $this->tenantId,
            'participant_id'               => $participant->id,
            'trigger_type'                 => 'hospitalization',
            'trigger_date'                 => $admissionDate,
            'trigger_source'               => 'adt_connector',
            'source_integration_log_id'    => $logEntry->id,
            'idt_review_due_date'           => Carbon::parse($admissionDate)
                ->addDays(SignificantChangeEvent::IDT_REVIEW_DUE_DAYS)
                ->toDateString(),
            'status'                       => 'pending',
        ]);

        // W4-8: Create draft transition-of-care note for clinical team to complete.
        // 42 CFR §460.104(b): IDT must be notified of significant changes; auto-populated
        // draft ensures the admission is documented and prompts care coordination.
        $this->createTransitionNote($participant, 'hospital_admission', $facility, $admissionDate);

        AuditLog::record(
            action:       'integration.hl7.admission',
            resourceType: 'Participant',
            resourceId:   $participant->id,
            tenantId:     $this->tenantId,
            newValues:    ['message_type' => 'A01', 'facility' => $facility],
        );

        $logEntry->markProcessed();
    }

    // ── ADT A03: Hospital Discharge ────────────────────────────────────────────

    /**
     * Discharge: create SDR (72-hour follow-up rule) + flag care plan for review + IDT alert.
     * CMS PACE rule: all hospitalizations must trigger an SDR within 72 hours of discharge.
     * IDT is alerted to coordinate med reconciliation and care plan reassessment.
     */
    private function handleA03(
        IntegrationLog $logEntry,
        Participant    $participant,
        AlertService   $alertService,
    ): void {
        $facility = $this->payload['facility'] ?? 'External Facility';

        // Create SDR — boot() auto-sets due_at = now() + 72h (42 CFR 460 enforcement)
        // requesting_user_id is nullable: system-generated SDRs have no requesting user
        Sdr::create([
            'tenant_id'             => $this->tenantId,
            'participant_id'        => $participant->id,
            'submitted_at'          => now(),
            'requesting_department' => 'it_admin',
            'assigned_department'   => 'idt', // discharge follow-up routes to IDT for care plan review
            'request_type'          => 'other', // discharge follow-up (no dedicated type)
            'description'           => "Hospital discharge from {$facility} (HL7 A03)",
            'status'                => 'submitted',
            'priority'              => 'urgent',
        ]);

        // Put the active care plan under review — discharge requires plan reassessment
        CarePlan::where('tenant_id', $this->tenantId)
            ->where('participant_id', $participant->id)
            ->where('status', 'active')
            ->update(['status' => 'under_review']);

        // Alert IDT to review meds and care plan after discharge
        $alertService->create([
            'tenant_id'          => $this->tenantId,
            'participant_id'     => $participant->id,
            'source_module'      => 'integration',
            'alert_type'         => 'discharge_followup',
            'title'              => "Participant Discharged: {$participant->full_name}",
            'message'            => "{$participant->full_name} (MRN: {$participant->mrn}) discharged from {$facility}. Review meds + care plan. SDR due within 72 hours.",
            'severity'           => 'warning',
            'target_departments' => ['idt'],
            'created_by_system'  => true,
        ]);

        // W4-8: Create draft transition-of-care note for discharge follow-up.
        // Prompts IDT to document the discharge transition per 42 CFR §460.104.
        $this->createTransitionNote($participant, 'hospital_discharge', $facility, now()->toDateString());

        AuditLog::record(
            action:       'integration.hl7.discharge',
            resourceType: 'Participant',
            resourceId:   $participant->id,
            tenantId:     $this->tenantId,
            newValues:    ['message_type' => 'A03', 'facility' => $facility],
        );

        $logEntry->markProcessed();
    }

    // ── ADT A08: Demographic / Info Update ────────────────────────────────────

    /** Update messages: audit log only, no clinical actions required. */
    private function handleA08(IntegrationLog $logEntry): void
    {
        AuditLog::record(
            action:       'integration.hl7.update',
            resourceType: 'IntegrationLog',
            resourceId:   $logEntry->id,
            tenantId:     $this->tenantId,
            newValues:    ['message_type' => 'A08', 'patient_mrn' => $this->payload['patient_mrn'] ?? null],
        );

        $logEntry->markProcessed();
    }

    // ── Transition-of-care draft note ─────────────────────────────────────────

    /**
     * Creates a DRAFT transition-of-care clinical note pre-populated with ADT data.
     * The note is attributed to the first active IT-admin user for the tenant
     * (system-authored), then left as draft for a clinician to review and sign.
     * If no active user exists for the tenant the note is silently skipped.
     *
     * @param string $transitionType  'hospital_admission' or 'hospital_discharge'
     * @param string $facility        Facility name from ADT payload
     * @param string $eventDate       ISO date string (YYYY-MM-DD)
     */
    private function createTransitionNote(
        Participant $participant,
        string      $transitionType,
        string      $facility,
        string      $eventDate,
    ): void {
        // Find a system author for the note (IT admin preferred, any active user fallback)
        $systemUser = User::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->where('department', 'it_admin')
            ->first()
            ?? User::where('tenant_id', $this->tenantId)
                ->where('is_active', true)
                ->first();

        if (! $systemUser) {
            Log::warning('[ProcessHl7AdtJob] No active user found for tenant — skipping transition note', [
                'tenant_id'       => $this->tenantId,
                'participant_id'  => $participant->id,
                'transition_type' => $transitionType,
            ]);
            return;
        }

        ClinicalNote::create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $this->tenantId,
            'site_id'             => $participant->site_id,
            'note_type'           => 'transition_of_care',
            'authored_by_user_id' => $systemUser->id,
            'department'          => 'it_admin',
            'status'              => ClinicalNote::STATUS_DRAFT,
            'visit_type'          => 'in_center',
            'visit_date'          => $eventDate,
            'content'             => [
                'transition_type'         => $transitionType,
                'facility'                => $facility,
                'event_date'              => $eventDate,
                'auto_populated'          => true,
                'review_instructions'     => 'Draft auto-created from HL7 ADT event. Please review, complete, and sign.',
            ],
        ]);
    }

    // ── Unknown message type ───────────────────────────────────────────────────

    private function handleUnknownType(IntegrationLog $logEntry, string $messageType): void
    {
        Log::warning('[ProcessHl7AdtJob] Unknown ADT message type', [
            'message_type'       => $messageType,
            'integration_log_id' => $logEntry->id,
        ]);

        $logEntry->markFailed("Unsupported ADT message type: {$messageType}");
    }
}
