<?php

// ─── LabResultFhirTest ────────────────────────────────────────────────────────
// Feature tests for FHIR R4 DiagnosticReport endpoint after W5-2 upgrade.
// Verifies that diagnosticReports() now pulls from emr_lab_results (not integration_log)
// and that components are properly mapped as contained FHIR Observations.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabResultFhirTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private string      $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant      = Tenant::factory()->create();
        $this->site        = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        // Create a FHIR API token with diagnosticreport.read scope
        $rawToken    = 'test-fhir-token-dr-' . uniqid();
        $this->token = $rawToken;

        $tokenUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

        ApiToken::create([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $tokenUser->id,
            'token'       => hash('sha256', $rawToken),
            'description' => 'DiagnosticReport test token',
            'scopes'      => ['diagnosticreport.read'],
            'is_active'   => true,
        ]);
    }

    // ── DiagnosticReport endpoint ─────────────────────────────────────────────

    public function test_diagnostic_reports_returns_fhir_bundle(): void
    {
        LabResult::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'test_name'      => 'CBC with Differential',
            'test_code'      => '58410-2',
            'collected_at'   => now()->subDay(),
            'overall_status' => 'final',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson("/fhir/R4/DiagnosticReport?patient={$this->participant->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/fhir+json');

        $this->assertEquals('Bundle', $response->json('resourceType'));
        $this->assertEquals('searchset', $response->json('type'));
        $this->assertCount(1, $response->json('entry'));

        $report = $response->json('entry.0.resource');
        $this->assertEquals('DiagnosticReport', $report['resourceType']);
        $this->assertEquals('final', $report['status']);
        $this->assertEquals('CBC with Differential', $report['code']['text']);
        $this->assertEquals("Patient/{$this->participant->id}", $report['subject']['reference']);
    }

    public function test_diagnostic_report_contains_component_observations(): void
    {
        $lab = LabResult::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'test_name'      => 'BMP',
            'collected_at'   => now()->subDay(),
        ]);

        LabResultComponent::create([
            'lab_result_id'   => $lab->id,
            'component_name'  => 'Sodium',
            'component_code'  => '2951-2',
            'value'           => '138',
            'unit'            => 'mEq/L',
            'reference_range' => '136-145',
            'abnormal_flag'   => 'normal',
        ]);
        LabResultComponent::create([
            'lab_result_id'   => $lab->id,
            'component_name'  => 'Potassium',
            'component_code'  => '2823-3',
            'value'           => '2.8',
            'unit'            => 'mEq/L',
            'reference_range' => '3.5-5.1',
            'abnormal_flag'   => 'critical_low',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson("/fhir/R4/DiagnosticReport?patient={$this->participant->id}")
            ->assertOk();

        $report    = $response->json('entry.0.resource');
        $contained = $report['contained'];
        $results   = $report['result'];

        $this->assertCount(2, $contained);
        $this->assertCount(2, $results);

        $this->assertEquals('Observation', $contained[0]['resourceType']);
        $this->assertEquals('Sodium', $contained[0]['code']['text']);

        // Critical low component should have interpretation coding
        $potassiumObs = $contained[1];
        $this->assertEquals('Potassium', $potassiumObs['code']['text']);
        $this->assertArrayHasKey('interpretation', $potassiumObs);
        $this->assertEquals('LL', $potassiumObs['interpretation'][0]['coding'][0]['code']);
    }

    public function test_diagnostic_reports_requires_patient_param(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson('/fhir/R4/DiagnosticReport')
            ->assertStatus(400);
    }

    public function test_diagnostic_reports_returns_empty_bundle_for_no_results(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson("/fhir/R4/DiagnosticReport?patient={$this->participant->id}")
            ->assertOk();

        $this->assertCount(0, $response->json('entry'));
    }

    public function test_cross_tenant_diagnostic_report_returns_404(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson("/fhir/R4/DiagnosticReport?patient={$otherParticipant->id}")
            ->assertNotFound();
    }

    public function test_cancelled_results_excluded_from_fhir_bundle(): void
    {
        // Cancelled results should not appear
        LabResult::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'overall_status' => 'cancelled',
            'collected_at'   => now()->subDay(),
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson("/fhir/R4/DiagnosticReport?patient={$this->participant->id}")
            ->assertOk();

        $this->assertCount(0, $response->json('entry'));
    }
}
