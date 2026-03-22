<?php

// ─── FhirApiTest ──────────────────────────────────────────────────────────────
// Feature tests for Phase 6C FHIR R4 API.
//
// Coverage:
//   - Valid Bearer token returns correct FHIR resourceType
//   - Missing token returns 401 OperationOutcome
//   - Expired token returns 401 OperationOutcome
//   - Cross-tenant participant returns 404 OperationOutcome
//   - Wrong scope returns 403 OperationOutcome
//   - Patient/{id} endpoint returns Patient resource with MR identifier
//   - Observation endpoint returns Bundle with Observation entries
//   - MedicationRequest endpoint returns Bundle
//   - Condition endpoint returns Bundle
//   - AllergyIntolerance endpoint returns Bundle
//   - CarePlan endpoint returns Bundle
//   - Appointment endpoint returns Bundle
//   - Every read is logged to audit_log with source_type='fhir_api'
//   - Missing ?patient= param returns 400
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\ApiToken;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create an API token and return [ApiToken, plaintext].
     * The factory hashes the token; we need the plaintext for the Authorization header.
     */
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

    // ── Auth: missing / invalid / expired ─────────────────────────────────────

    public function test_missing_bearer_token_returns_401(): void
    {
        $participant = Participant::factory()->create();

        $this->getJson("/fhir/R4/Patient/{$participant->id}")
            ->assertStatus(401)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->getJson('/fhir/R4/Patient/1', ['Authorization' => 'Bearer invalidtoken'])
            ->assertStatus(401)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_expired_token_returns_401(): void
    {
        [$token, $plaintext] = $this->makeToken(['expires_at' => now()->subDay()]);
        $participant = $this->makeParticipantForToken($token);

        $this->getJson("/fhir/R4/Patient/{$participant->id}", $this->fhirHeader($plaintext))
            ->assertStatus(401)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_wrong_scope_returns_403(): void
    {
        [$token, $plaintext] = $this->makeToken(['scopes' => ['observation.read']]); // no patient.read
        $participant = $this->makeParticipantForToken($token);

        $this->getJson("/fhir/R4/Patient/{$participant->id}", $this->fhirHeader($plaintext))
            ->assertStatus(403)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_cross_tenant_patient_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        // participant belongs to a DIFFERENT tenant
        $cross = Participant::factory()->create();

        $this->getJson("/fhir/R4/Patient/{$cross->id}", $this->fhirHeader($plaintext))
            ->assertStatus(404)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_cross_tenant_observations_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $cross = Participant::factory()->create();

        $this->getJson("/fhir/R4/Observation?patient={$cross->id}", $this->fhirHeader($plaintext))
            ->assertStatus(404);
    }

    // ── Patient endpoint ──────────────────────────────────────────────────────

    public function test_patient_endpoint_returns_patient_resource(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $response = $this->getJson("/fhir/R4/Patient/{$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Patient')
            ->assertJsonPath('id', (string) $participant->id);

        $response->assertHeader('Content-Type', 'application/fhir+json');
    }

    public function test_patient_resource_has_mrn_identifier_with_mr_code(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $response = $this->getJson("/fhir/R4/Patient/{$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk();

        // Find the MRN identifier (type code = "MR")
        $identifiers = $response->json('identifier');
        $mrnIdentifier = collect($identifiers)->first(
            fn ($id) => collect($id['type']['coding'] ?? [])->contains('code', 'MR')
        );

        $this->assertNotNull($mrnIdentifier, 'Patient should have an MR (MRN) identifier');
        $this->assertEquals($participant->mrn, $mrnIdentifier['value']);
    }

    public function test_patient_read_creates_audit_log(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $this->getJson("/fhir/R4/Patient/{$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.patient',
            'resource_type' => 'Patient',
            'resource_id'   => $participant->id,
        ]);
    }

    // ── Observation endpoint ──────────────────────────────────────────────────

    public function test_observation_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Vital::factory()->count(3)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $this->getJson("/fhir/R4/Observation?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset');
    }

    public function test_observation_missing_patient_param_returns_400(): void
    {
        [$token, $plaintext] = $this->makeToken();

        $this->getJson('/fhir/R4/Observation', $this->fhirHeader($plaintext))
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_observation_entries_have_correct_resource_type(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Vital::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
            'bp_systolic'    => 120,
            'bp_diastolic'   => 80,
        ]);

        $response = $this->getJson(
            "/fhir/R4/Observation?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $entries = $response->json('entry');
        $this->assertNotEmpty($entries);
        $this->assertEquals('Observation', $entries[0]['resource']['resourceType']);
    }

    // ── MedicationRequest endpoint ────────────────────────────────────────────

    public function test_medication_request_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Medication::factory()->count(2)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $this->getJson("/fhir/R4/MedicationRequest?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');
    }

    // ── Condition endpoint ────────────────────────────────────────────────────

    public function test_condition_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Problem::factory()->count(2)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $this->getJson("/fhir/R4/Condition?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');
    }

    // ── AllergyIntolerance endpoint ───────────────────────────────────────────

    public function test_allergy_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Allergy::factory()->count(2)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
            'is_active'      => true,
        ]);

        $this->getJson("/fhir/R4/AllergyIntolerance?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');
    }

    // ── CarePlan endpoint ─────────────────────────────────────────────────────

    public function test_care_plan_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        // One care plan per participant (unique constraint on participant_id + version)
        CarePlan::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
            'status'         => 'active',
        ]);

        $this->getJson("/fhir/R4/CarePlan?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');
    }

    // ── Appointment endpoint ──────────────────────────────────────────────────

    public function test_appointment_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->count(2)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $this->getJson("/fhir/R4/Appointment?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');
    }

    // ── Audit log for all reads ───────────────────────────────────────────────

    public function test_observation_read_creates_audit_log(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $this->getJson("/fhir/R4/Observation?patient={$participant->id}", $this->fhirHeader($plaintext))
            ->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.observation',
            'resource_type' => 'Observation',
            'resource_id'   => $participant->id,
        ]);
    }
}
