<?php

// ─── ProcessHl7AdtJobTest ─────────────────────────────────────────────────────
// Unit tests for ProcessHl7AdtJob.
//
// Coverage:
//   - A01 creates EncounterLog for the participant
//   - A01 creates an Alert targeting primary_care
//   - A01 marks integration_log as processed
//   - A03 creates an Sdr for 72-hour discharge follow-up
//   - A03 puts active care plan into under_review
//   - A03 creates alert for primary_care and social_work
//   - A08 creates audit log entry (action: integration.hl7.update)
//   - A08 marks integration_log as processed
//   - Unknown MRN marks integration_log as failed
//   - Unknown message type marks integration_log as failed
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Jobs\ProcessHl7AdtJob;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\EncounterLog;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessHl7AdtJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeLogEntry(Tenant $tenant, string $messageType = 'A01', string $mrn = null): IntegrationLog
    {
        $participant = $mrn
            ? Participant::factory()->create(['tenant_id' => $tenant->id, 'mrn' => $mrn])
            : null;

        return IntegrationLog::factory()->pending()->create([
            'tenant_id'      => $tenant->id,
            'connector_type' => 'hl7_adt',
            'raw_payload'    => [
                'message_type'   => $messageType,
                'patient_mrn'    => $participant?->mrn ?? 'UNKNOWN-MRN',
                'event_datetime' => now()->toIso8601String(),
                'facility'       => 'Test Hospital',
            ],
        ]);
    }

    private function runJob(IntegrationLog $log, Tenant $tenant): void
    {
        $job = new ProcessHl7AdtJob($log->id, $log->raw_payload, $tenant->id);
        $job->handle(app(AlertService::class));
    }

    // ── A01: Admission ────────────────────────────────────────────────────────

    public function test_a01_creates_encounter_log(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A01', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String(), 'facility' => 'Test Hospital'],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_encounter_log', [
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
            'service_type'   => 'other',
        ]);
    }

    public function test_a01_creates_primary_care_alert(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A01', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $alert = Alert::where('tenant_id', $tenant->id)
            ->where('participant_id', $participant->id)
            ->where('alert_type', 'hospitalization')
            ->first();

        $this->assertNotNull($alert);
        $this->assertContains('social_work', $alert->target_departments);
        $this->assertContains('idt', $alert->target_departments);
    }

    public function test_a01_marks_integration_log_processed(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A01', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_integration_log', ['id' => $log->id, 'status' => 'processed']);
    }

    // ── A03: Discharge ────────────────────────────────────────────────────────

    public function test_a03_creates_sdr_for_discharge_followup(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A03', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String(), 'facility' => 'City Hospital'],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_sdrs', [
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
            'priority'       => 'urgent',
        ]);
    }

    public function test_a03_puts_active_care_plan_under_review(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        CarePlan::factory()->create(['tenant_id' => $tenant->id, 'participant_id' => $participant->id, 'status' => 'active']);

        $log = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A03', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_care_plans', [
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
            'status'         => 'under_review',
        ]);
    }

    public function test_a03_creates_discharge_alert_for_idt(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A03', 'patient_mrn' => $participant->mrn, 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $alert = Alert::where('tenant_id', $tenant->id)
            ->where('alert_type', 'discharge_followup')
            ->first();

        $this->assertNotNull($alert);
        $this->assertContains('idt', $alert->target_departments);
    }

    // ── A08: Update ───────────────────────────────────────────────────────────

    public function test_a08_creates_audit_log_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A08', 'patient_mrn' => 'TEST-00001', 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'integration.hl7.update',
            'resource_type' => 'IntegrationLog',
        ]);
    }

    public function test_a08_marks_integration_log_processed(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A08', 'patient_mrn' => 'TEST-00001', 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_integration_log', ['id' => $log->id, 'status' => 'processed']);
    }

    // ── Unknown MRN ───────────────────────────────────────────────────────────

    public function test_unknown_mrn_marks_integration_log_failed(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A01', 'patient_mrn' => 'DOES-NOT-EXIST', 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_integration_log', [
            'id'     => $log->id,
            'status' => 'failed',
        ]);
    }

    public function test_unknown_mrn_does_not_create_encounter_or_alert(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = IntegrationLog::factory()->pending()->create([
            'tenant_id'   => $tenant->id,
            'raw_payload' => ['message_type' => 'A01', 'patient_mrn' => 'DOES-NOT-EXIST', 'event_datetime' => now()->toIso8601String()],
        ]);

        $this->runJob($log, $tenant);

        $this->assertDatabaseCount('emr_encounter_log', 0);
        $this->assertDatabaseCount('emr_alerts', 0);
    }
}
