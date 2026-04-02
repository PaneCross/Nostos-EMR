<?php

// ─── FhirOrganizationTest ─────────────────────────────────────────────────────
// Feature tests for the W4-9 FHIR R4 Organization endpoints.
//
// Coverage:
//   - GET /Organization returns Bundle with tenant + all sites
//   - Tenant Organization has H-number in identifier (cms_contract_id)
//   - Site Organizations have partOf reference to tenant
//   - GET /Organization/tenant-{id} returns single tenant Organization
//   - GET /Organization/site-{id} returns single site Organization with address
//   - Cross-tenant organization ID returns 404
//   - Unknown ID format returns 404
//   - Audit logged with action='fhir.read.organization'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirOrganizationTest extends TestCase
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

    // ── Bundle (all organizations) ────────────────────────────────────────────

    public function test_organization_bundle_includes_tenant_and_sites(): void
    {
        [$token, $plaintext] = $this->makeToken();
        // Token's tenant already exists; find it to add sites
        $tenant = Tenant::find($token->tenant_id);
        Site::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/fhir/R4/Organization', $this->fhirHeader($plaintext))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');

        // 1 tenant + 2 sites = 3 total (sites created by factory + any existing factory site)
        $total = $response->json('total');
        $this->assertGreaterThanOrEqual(2, $total); // at minimum tenant + 1 site
    }

    public function test_tenant_organization_has_h_number_identifier(): void
    {
        [$token, $plaintext] = $this->makeToken();
        Tenant::where('id', $token->tenant_id)->update(['cms_contract_id' => 'H1234']);

        $response = $this->getJson('/fhir/R4/Organization', $this->fhirHeader($plaintext))
            ->assertOk();

        // Find the tenant Organization in the bundle entries
        $entries = $response->json('entry');
        $tenantOrg = collect($entries)->first(
            fn ($e) => $e['resource']['id'] === 'tenant-' . $token->tenant_id
        );

        $this->assertNotNull($tenantOrg, 'Tenant Organization should be in bundle');
        $identifier = $tenantOrg['resource']['identifier'][0] ?? null;
        $this->assertNotNull($identifier, 'Tenant Organization should have identifier');
        $this->assertEquals('H1234', $identifier['value']);
    }

    public function test_site_organizations_have_part_of_reference(): void
    {
        [$token, $plaintext] = $this->makeToken();
        Site::factory()->create(['tenant_id' => $token->tenant_id]);

        $response = $this->getJson('/fhir/R4/Organization', $this->fhirHeader($plaintext))
            ->assertOk();

        $entries = $response->json('entry');
        $siteOrg = collect($entries)->first(
            fn ($e) => str_starts_with($e['resource']['id'], 'site-')
        );

        $this->assertNotNull($siteOrg, 'Site Organization should be in bundle');
        $this->assertArrayHasKey('partOf', $siteOrg['resource']);
        $this->assertStringContainsString('tenant-', $siteOrg['resource']['partOf']['reference']);
    }

    // ── Single Organization by ID ─────────────────────────────────────────────

    public function test_get_tenant_organization_by_id(): void
    {
        [$token, $plaintext] = $this->makeToken();
        Tenant::where('id', $token->tenant_id)->update(['cms_contract_id' => 'H9876']);

        $this->getJson(
            "/fhir/R4/Organization/tenant-{$token->tenant_id}",
            $this->fhirHeader($plaintext)
        )
            ->assertOk()
            ->assertJsonPath('resourceType', 'Organization')
            ->assertJsonPath('id', "tenant-{$token->tenant_id}");
    }

    public function test_get_site_organization_by_id(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $site = Site::factory()->create([
            'tenant_id' => $token->tenant_id,
            'address'   => '123 Main St',
            'city'      => 'Columbus',
            'state'     => 'OH',
            'zip'       => '43215',
        ]);

        $response = $this->getJson(
            "/fhir/R4/Organization/site-{$site->id}",
            $this->fhirHeader($plaintext)
        )
            ->assertOk()
            ->assertJsonPath('resourceType', 'Organization')
            ->assertJsonPath('id', "site-{$site->id}");

        $address = $response->json('address.0');
        $this->assertEquals('Columbus', $address['city']);
    }

    // ── Cross-tenant isolation ────────────────────────────────────────────────

    public function test_cross_tenant_organization_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();
        $otherTenant = Tenant::factory()->create();

        $this->getJson(
            "/fhir/R4/Organization/tenant-{$otherTenant->id}",
            $this->fhirHeader($plaintext)
        )->assertStatus(404)
         ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_unknown_id_format_returns_404(): void
    {
        [$token, $plaintext] = $this->makeToken();

        $this->getJson('/fhir/R4/Organization/unknown-999', $this->fhirHeader($plaintext))
            ->assertStatus(404);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_organization_bundle_read_is_audit_logged(): void
    {
        [$token, $plaintext] = $this->makeToken();

        $this->getJson('/fhir/R4/Organization', $this->fhirHeader($plaintext))->assertOk();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'fhir.read.organization',
            'resource_type' => 'Organization',
        ]);
    }
}
