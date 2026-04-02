<?php

// ─── FhirEncounterTest ────────────────────────────────────────────────────────
// Feature tests for the W4-9 FHIR R4 Encounter endpoint.
//
// Coverage:
//   - Bundle returned with resourceType=Bundle, type=searchset
//   - Each entry has resource.resourceType='Encounter'
//   - Subject reference contains patient ID
//   - Missing ?patient= param returns 400
//   - Cross-tenant participant returns 404
//   - Read is logged to audit_log with action='fhir.read.encounter'
//   - Status mapping: scheduled→planned, completed→finished
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirEncounterTest extends TestCase
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

    // ── Bundle structure ──────────────────────────────────────────────────────

    public function test_encounter_endpoint_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->count(2)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset')
            ->assertJsonPath('total', 2);
    }

    public function test_encounter_entries_have_correct_resource_type(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $response = $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $entries = $response->json('entry');
        $this->assertNotEmpty($entries);
        $this->assertEquals('Encounter', $entries[0]['resource']['resourceType']);
    }

    public function test_encounter_subject_reference_contains_patient_id(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
        ]);

        $response = $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $subject = $response->json('entry.0.resource.subject.reference');
        $this->assertStringContainsString((string) $participant->id, $subject);
    }

    // ── Status mapping ────────────────────────────────────────────────────────

    public function test_scheduled_appointment_maps_to_planned_encounter_status(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
            'status'         => 'scheduled',
        ]);

        $response = $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $this->assertEquals('planned', $response->json('entry.0.resource.status'));
    }

    public function test_completed_appointment_maps_to_finished_encounter_status(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        Appointment::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $token->tenant_id,
            'status'         => 'completed',
        ]);

        $response = $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $this->assertEquals('finished', $response->json('entry.0.resource.status'));
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function test_missing_patient_param_returns_400(): void
    {
        [$token, $plaintext] = $this->makeToken();

        $this->getJson('/fhir/R4/Encounter', $this->fhirHeader($plaintext))
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_cross_tenant_participant_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $cross = Participant::factory()->create(); // different tenant

        $this->getJson(
            "/fhir/R4/Encounter?patient={$cross->id}",
            $this->fhirHeader($plaintext)
        )->assertStatus(404)
         ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_encounter_read_is_audit_logged(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $participant = $this->makeParticipantForToken($token);

        $this->getJson(
            "/fhir/R4/Encounter?patient={$participant->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.encounter',
            'resource_type' => 'Encounter',
            'resource_id'   => $participant->id,
        ]);
    }
}
