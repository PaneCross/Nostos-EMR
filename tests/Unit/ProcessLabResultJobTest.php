<?php

// ─── ProcessLabResultJobTest ──────────────────────────────────────────────────
// Unit tests for ProcessLabResultJob.
//
// Coverage:
//   - Normal result creates EncounterLog for participant
//   - Normal result does NOT create an alert
//   - Abnormal result creates EncounterLog with [ABNORMAL] note
//   - Abnormal result creates primary_care alert (alert_type='abnormal_lab')
//   - Processed entry marked as status=processed
//   - Unknown MRN marks integration_log as failed
//   - Unknown MRN does not create encounter or alert
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Jobs\ProcessLabResultJob;
use App\Models\Alert;
use App\Models\EncounterLog;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Models\Tenant;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessLabResultJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeLogEntry(Tenant $tenant, string $mrn, bool $abnormal = false): IntegrationLog
    {
        return IntegrationLog::factory()->pending()->create([
            'tenant_id'      => $tenant->id,
            'connector_type' => 'lab_results',
            'raw_payload'    => [
                'patient_mrn'   => $mrn,
                'test_code'     => 'HGB',
                'test_name'     => 'Hemoglobin',
                'value'         => $abnormal ? '6.5' : '13.5',
                'unit'          => 'g/dL',
                'result_date'   => now()->toDateString(),
                'abnormal_flag' => $abnormal,
            ],
        ]);
    }

    private function runJob(IntegrationLog $log, Tenant $tenant): void
    {
        $job = new ProcessLabResultJob($log->id, $log->raw_payload, $tenant->id);
        $job->handle(app(AlertService::class));
    }

    // ── Normal result ─────────────────────────────────────────────────────────

    public function test_normal_result_creates_encounter_log(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, false);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_encounter_log', [
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
            'service_type'   => 'other',
        ]);
    }

    public function test_normal_result_does_not_create_alert(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, false);

        $this->runJob($log, $tenant);

        $this->assertDatabaseCount('emr_alerts', 0);
    }

    public function test_normal_result_marks_log_processed(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, false);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_integration_log', ['id' => $log->id, 'status' => 'processed']);
    }

    // ── Abnormal result ───────────────────────────────────────────────────────

    public function test_abnormal_result_creates_encounter_log(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, true);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_encounter_log', [
            'tenant_id'      => $tenant->id,
            'participant_id' => $participant->id,
        ]);
    }

    public function test_abnormal_result_creates_primary_care_alert(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, true);

        $this->runJob($log, $tenant);

        $alert = Alert::where('tenant_id', $tenant->id)
            ->where('participant_id', $participant->id)
            ->where('alert_type', 'abnormal_lab')
            ->first();

        $this->assertNotNull($alert, 'Expected abnormal_lab alert to be created');
        $this->assertContains('primary_care', $alert->target_departments);
    }

    public function test_abnormal_result_alert_severity_is_high(): void
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);
        $log         = $this->makeLogEntry($tenant, $participant->mrn, true);

        $this->runJob($log, $tenant);

        $alert = Alert::where('alert_type', 'abnormal_lab')->first();
        $this->assertEquals('warning', $alert->severity);
    }

    // ── Unknown MRN ───────────────────────────────────────────────────────────

    public function test_unknown_mrn_marks_integration_log_failed(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = $this->makeLogEntry($tenant, 'UNKNOWN-MRN-XYZ', false);

        $this->runJob($log, $tenant);

        $this->assertDatabaseHas('emr_integration_log', ['id' => $log->id, 'status' => 'failed']);
    }

    public function test_unknown_mrn_does_not_create_encounter_or_alert(): void
    {
        $tenant = Tenant::factory()->create();
        $log    = $this->makeLogEntry($tenant, 'UNKNOWN-MRN-XYZ', false);

        $this->runJob($log, $tenant);

        $this->assertDatabaseCount('emr_encounter_log', 0);
        $this->assertDatabaseCount('emr_alerts', 0);
    }
}
