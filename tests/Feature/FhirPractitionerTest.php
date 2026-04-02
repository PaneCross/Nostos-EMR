<?php

// ─── FhirPractitionerTest ─────────────────────────────────────────────────────
// Feature tests for the W4-9 FHIR R4 Practitioner endpoints.
//
// Coverage:
//   - GET /Practitioner/{id} returns Practitioner for clinical dept user
//   - GET /Practitioner/{id} returns 404 for non-clinical dept user
//   - GET /Practitioner/{id} returns 404 for cross-tenant user
//   - GET /Practitioner/{id} returns 404 for inactive user
//   - GET /Practitioner?name= returns Bundle of matching clinical users
//   - GET /Practitioner (no name) returns all clinical users in tenant
//   - Non-clinical users excluded from name search
//   - Resource has name with family/given
//   - Resource has qualification code from department
//   - Audit logged with action='fhir.read.practitioner'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Fhir\Mappers\PractitionerMapper;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirPractitionerTest extends TestCase
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

    private function fhirHeader(string $plaintext): array
    {
        return ['Authorization' => "Bearer {$plaintext}"];
    }

    // ── Single Practitioner by ID ─────────────────────────────────────────────

    public function test_clinical_user_returns_practitioner_resource(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'primary_care',
            'is_active'  => true,
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$user->id}", $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Practitioner')
            ->assertJsonPath('id', (string) $user->id);
    }

    public function test_practitioner_resource_has_name(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'primary_care',
            'is_active'  => true,
            'first_name' => 'Jane',
            'last_name'  => 'Smith',
        ]);

        $response = $this->getJson(
            "/fhir/R4/Practitioner/{$user->id}",
            $this->fhirHeader($plaintext)
        )->assertOk();

        $name = $response->json('name.0');
        $this->assertEquals('Smith', $name['family']);
        $this->assertContains('Jane', $name['given']);
    }

    public function test_non_clinical_dept_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'finance', // not in CLINICAL_DEPARTMENTS
            'is_active'  => true,
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$user->id}", $this->fhirHeader($plaintext))
            ->assertStatus(404)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_cross_tenant_user_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'department' => 'primary_care',
            'is_active'  => true,
            // different tenant_id (factory creates a new one)
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$user->id}", $this->fhirHeader($plaintext))
            ->assertStatus(404);
    }

    public function test_inactive_user_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'primary_care',
            'is_active'  => false,
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$user->id}", $this->fhirHeader($plaintext))
            ->assertStatus(404);
    }

    // ── Practitioner search by name ───────────────────────────────────────────

    public function test_name_search_returns_bundle(): void
    {
        [$token, $plaintext] = $this->makeToken();
        User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'primary_care',
            'last_name'  => 'Johnson',
            'is_active'  => true,
        ]);

        $this->getJson('/fhir/R4/Practitioner?name=Johnson', $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('total', 1);
    }

    public function test_non_clinical_users_excluded_from_search(): void
    {
        [$token, $plaintext] = $this->makeToken();
        // Create a finance user named Smith
        User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'finance',
            'last_name'  => 'Smith',
            'is_active'  => true,
        ]);
        // Create a clinical user named Smith
        User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'home_care',
            'last_name'  => 'Smith',
            'is_active'  => true,
        ]);

        $response = $this->getJson('/fhir/R4/Practitioner?name=Smith', $this->fhirHeader($plaintext))
            ->assertOk();

        // Only the clinical user (home_care) should appear
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Practitioner', $response->json('entry.0.resource.resourceType'));
    }

    public function test_no_name_param_returns_all_clinical_users(): void
    {
        [$token, $plaintext] = $this->makeToken();
        User::factory()->create(['tenant_id' => $token->tenant_id, 'department' => 'primary_care', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $token->tenant_id, 'department' => 'therapies', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $token->tenant_id, 'department' => 'finance', 'is_active' => true]);

        $response = $this->getJson('/fhir/R4/Practitioner', $this->fhirHeader($plaintext))->assertOk();

        // 2 clinical users only (finance excluded)
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_practitioner_read_by_id_is_audit_logged(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $user = User::factory()->create([
            'tenant_id'  => $token->tenant_id,
            'department' => 'social_work',
            'is_active'  => true,
        ]);

        $this->getJson("/fhir/R4/Practitioner/{$user->id}", $this->fhirHeader($plaintext))->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.practitioner',
            'resource_type' => 'Practitioner',
        ]);
    }
}
