<?php

// ─── Hl7ConnectorTest ─────────────────────────────────────────────────────────
// Feature tests for the HL7 ADT integration endpoint.
//
// Coverage:
//   - Valid A01 payload → 202, creates integration_log, dispatches job
//   - Valid A03 payload → 202, creates integration_log
//   - Valid A08 payload → 202, creates integration_log
//   - Missing required fields → 422 validation error
//   - Invalid message_type → 422
//   - Missing X-Integration-Tenant header → 401
//   - Invalid tenant ID → 401
//   - Integration log entry has status=pending after receipt
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\ProcessHl7AdtJob;
use App\Models\IntegrationLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Hl7ConnectorTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeHeader(int $tenantId): array
    {
        return ['X-Integration-Tenant' => (string) $tenantId];
    }

    private function validA01Payload(string $mrn = 'TEST-00001'): array
    {
        return [
            'message_type'   => 'A01',
            'patient_mrn'    => $mrn,
            'event_datetime' => now()->toIso8601String(),
            'facility'       => 'Sunrise General Hospital',
        ];
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_missing_tenant_header_returns_401(): void
    {
        $this->postJson('/integrations/hl7/adt', $this->validA01Payload())
            ->assertStatus(401);
    }

    public function test_invalid_tenant_id_returns_401(): void
    {
        $this->postJson('/integrations/hl7/adt', $this->validA01Payload(), ['X-Integration-Tenant' => '99999'])
            ->assertStatus(401);
    }

    // ── A01: Admission ────────────────────────────────────────────────────────

    public function test_valid_a01_payload_returns_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', $this->validA01Payload(), $this->makeHeader($tenant->id))
            ->assertStatus(202)
            ->assertJsonPath('received', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonStructure(['integration_log_id']);
    }

    public function test_valid_a01_creates_integration_log_entry(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', $this->validA01Payload(), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        $this->assertDatabaseHas('emr_integration_log', [
            'tenant_id'      => $tenant->id,
            'connector_type' => 'hl7_adt',
            'direction'      => 'inbound',
            'status'         => 'pending',
        ]);
    }

    public function test_valid_a01_dispatches_process_job(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', $this->validA01Payload(), $this->makeHeader($tenant->id))
            ->assertStatus(202);

        Queue::assertPushed(ProcessHl7AdtJob::class);
    }

    // ── A03: Discharge ────────────────────────────────────────────────────────

    public function test_valid_a03_payload_returns_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $payload = [
            'message_type'   => 'A03',
            'patient_mrn'    => 'TEST-00001',
            'event_datetime' => now()->toIso8601String(),
            'facility'       => 'City Medical Center',
        ];

        $this->postJson('/integrations/hl7/adt', $payload, $this->makeHeader($tenant->id))
            ->assertStatus(202);

        $this->assertDatabaseHas('emr_integration_log', ['connector_type' => 'hl7_adt', 'status' => 'pending']);
    }

    // ── A08: Update ───────────────────────────────────────────────────────────

    public function test_valid_a08_payload_returns_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $payload = [
            'message_type'   => 'A08',
            'patient_mrn'    => 'TEST-00001',
            'event_datetime' => now()->toIso8601String(),
        ];

        $this->postJson('/integrations/hl7/adt', $payload, $this->makeHeader($tenant->id))
            ->assertStatus(202);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_missing_message_type_returns_422(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', [
            'patient_mrn'    => 'TEST-00001',
            'event_datetime' => now()->toIso8601String(),
        ], $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message_type']);
    }

    public function test_invalid_message_type_returns_422(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', [
            'message_type'   => 'A99', // not in A01|A03|A08
            'patient_mrn'    => 'TEST-00001',
            'event_datetime' => now()->toIso8601String(),
        ], $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message_type']);
    }

    public function test_missing_patient_mrn_returns_422(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', [
            'message_type'   => 'A01',
            'event_datetime' => now()->toIso8601String(),
        ], $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_mrn']);
    }

    public function test_missing_event_datetime_returns_422(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJson('/integrations/hl7/adt', [
            'message_type' => 'A01',
            'patient_mrn'  => 'TEST-00001',
        ], $this->makeHeader($tenant->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_datetime']);
    }
}
