<?php

// ─── FhirDiagnosticReportTest ─────────────────────────────────────────────────
// Feature tests for the W4-9 FHIR R4 DiagnosticReport endpoint.
//
// Coverage:
//   - Bundle returned for participant with lab results in integration_log
//   - Each entry has resource.resourceType='DiagnosticReport'
//   - Category contains LAB code
//   - Status is 'final' for processed lab results
//   - Abnormal flag included in conclusion text
//   - Missing ?patient= returns 400
//   - Cross-tenant returns 404
//   - Audit logged with action='fhir.read.diagnosticreport'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirDiagnosticReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(array $state = []): array
    {
        $plaintext = Str::random(64);
        $token     = ApiToken::factory()->state(array_merge([
            'token' => ApiToken::hashToken($plaintext),
        ], $state))->create();
        return [$token, $plaintext];
    }

    private function makeParticipantForToken(ApiToken $token): Participant
    {
        return Participant::factory()->create(['tenant_id' => $token->tenant_id]);
    }

    private function fhirHeader(string $plaintext): array
    {
        return ['Authorization' => "Bearer {$plaintext}"];
    }

    /**
     * Create a processed lab result integration log entry for a participant.
     */
    private function makeLabResult(Participant $participant, int $tenantId, array $payloadOverrides = []): IntegrationLog
    {
        $payload = array_merge([
            'patient_mrn'   => $participant->mrn,
            'test_name'     => 'CBC with Differential',
            'result_value'  => '12.5',
            'result_unit'   => 'g/dL',
            'abnormal_flag' => false,
            'collected_at'  => now()->subHours(2)->toIso8601String(),
        ], $payloadOverrides);

        return IntegrationLog::create([
            'tenant_id'      => $tenantId,
            'connector_type' => 'lab_results',
            'direction'      => 'inbound',
            'status'         => 'processed',
            'raw_payload'    => $payload,
            'created_at'     => now()->toDateTimeString(),
        ]);
    }

    // ── Bundle structure ──────────────────────────────────────────────────────

    public function test_diagnostic_report_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id);

        $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset')
            ->assertJsonPath('total', 1);
    }

    public function test_diagnostic_report_entries_have_correct_resource_type(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id);

        $response = $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $entries = $response->json('entry');
        $this->assertNotEmpty($entries);
        $this->assertEquals('DiagnosticReport', $entries[0]['resource']['resourceType']);
    }

    public function test_lab_result_status_is_final(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id);

        $response = $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $this->assertEquals('final', $response->json('entry.0.resource.status'));
    }

    public function test_lab_category_contains_lab_code(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id);

        $response = $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $category = $response->json('entry.0.resource.category.0.coding.0');
        $this->assertEquals('LAB', $category['code']);
    }

    public function test_abnormal_flag_appears_in_conclusion(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id, [
            'result_value'  => '18.5',
            'result_unit'   => 'g/dL',
            'abnormal_flag' => true,
        ]);

        $response = $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $conclusion = $response->json('entry.0.resource.conclusion');
        $this->assertStringContainsString('ABNORMAL', $conclusion);
    }

    public function test_normal_result_has_no_abnormal_in_conclusion(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);
        $this->makeLabResult($participant, $token->tenant_id, [
            'result_value'  => '12.5',
            'abnormal_flag' => false,
        ]);

        $response = $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $conclusion = $response->json('entry.0.resource.conclusion');
        $this->assertStringNotContainsString('ABNORMAL', (string) $conclusion);
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function test_missing_patient_param_returns_400(): void
    {
        [$token, $plaintext] = $this->makeToken();

        $this->getJson('/fhir/R4/DiagnosticReport', $this->fhirHeader($plaintext))
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_cross_tenant_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $cross = Participant::factory()->create();

        $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$cross->id}",
            $this->fhirHeader($plaintext)
        )->assertStatus(404);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_diagnostic_report_read_is_audit_logged(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $this->getJson(
            "/fhir/R4/DiagnosticReport?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.diagnosticreport',
            'resource_type' => 'DiagnosticReport',
        ]);
    }
}
