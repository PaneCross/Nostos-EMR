<?php

// ─── LabResultConnectorTest ───────────────────────────────────────────────────
// Feature tests for the Lab Results integration endpoint.
//
// Coverage:
//   - Valid normal result → 202, creates integration_log, dispatches job
//   - Valid abnormal result → 202, creates integration_log
//   - Missing required fields → 422 validation error
//   - Missing tenant header → 401
//   - Integration log has correct connector_type = 'lab_results'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\ProcessLabResultJob;
use App\Models\IntegrationLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LabResultConnectorTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeHeader(int $tenantId): array
    {
        return ['X-Integration-Tenant' => (string) $tenantId];
    }

    private function validPayload(bool $abnormal = false): array
    {
        return [
            'patient_mrn'   => 'TEST-00001',
            'test_code'     => 'HGB',
            'test_name'     => 'Hemoglobin',
            'value'         => $abnormal ? '6.5' : '13.5',
            'unit'          => 'g/dL',
            'result_date'   => now()->toDateString(),
            'abnormal_flag' => $abnormal,
        ];
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_missing_tenant_header_returns_401(): void
    {
        $this->postJson('/integrations/labs/result', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_invalid_tenant_id_returns_401(): void
    {
        $this->postJson('/integrations/labs/result', $this->validPayload(), ['X-Integration-Tenant' => '99999'])
            ->assertStatus(401);
    }

    // ── Normal result ─────────────────────────────────────────────────────────

    public function test_valid_normal_result_returns_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/labs/result', $this->validPayload(), $this->makeHeader($tenant->id))
            ->assertStatus(202)
            ->assertJsonPath('received', true)
            ->assertJsonPath('status', 'queued');
    }

    public function test_valid_result_logs_to_integration_log(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/labs/result', $this->validPayload(), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        $this->assertDatabaseHas('emr_integration_log', [
            'tenant_id'      => $tenant->id,
            'connector_type' => 'lab_results',
            'direction'      => 'inbound',
            'status'         => 'pending',
        ]);
    }

    public function test_valid_result_dispatches_process_job(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/labs/result', $this->validPayload(), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        Queue::assertPushed(ProcessLabResultJob::class);
    }

    // ── Abnormal result ───────────────────────────────────────────────────────

    public function test_abnormal_result_returns_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/labs/result', $this->validPayload(abnormal: true), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        $this->assertDatabaseHas('emr_integration_log', [
            'tenant_id'      => $tenant->id,
            'connector_type' => 'lab_results',
        ]);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_missing_patient_mrn_returns_422(): void
    {
        $tenant  = Tenant::factory()->create();
        $payload = $this->validPayload();
        unset($payload['patient_mrn']);

        $this->postJson('/integrations/labs/result', $payload, $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_mrn']);
    }

    public function test_missing_test_code_returns_422(): void
    {
        $tenant  = Tenant::factory()->create();
        $payload = $this->validPayload();
        unset($payload['test_code']);

        $this->postJson('/integrations/labs/result', $payload, $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['test_code']);
    }

    public function test_missing_result_date_returns_422(): void
    {
        $tenant  = Tenant::factory()->create();
        $payload = $this->validPayload();
        unset($payload['result_date']);

        $this->postJson('/integrations/labs/result', $payload, $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result_date']);
    }

    public function test_integration_log_response_includes_log_id(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $response = $this->postJson('/integrations/labs/result', $this->validPayload(), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        $this->assertNotNull($response->json('integration_log_id'));

        $logEntry = IntegrationLog::find($response->json('integration_log_id'));
        $this->assertNotNull($logEntry);
        $this->assertEquals('lab_results', $logEntry->connector_type);
    }
}
