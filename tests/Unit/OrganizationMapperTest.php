<?php

// ─── OrganizationMapperTest ───────────────────────────────────────────────────
// Unit tests for OrganizationMapper (FHIR R4 Organization resource generation).
//
// Coverage:
//   - Tenant: resourceType='Organization', id='tenant-{id}', name, active
//   - Tenant: H-number identifier when cms_contract_id is present
//   - Tenant: empty identifier array when cms_contract_id is null
//   - Site: resourceType='Organization', id='site-{id}', name, active
//   - Site: address fields populated from model (line, city, state, postalCode)
//   - Site: partOf.reference points to 'Organization/tenant-{tenant_id}'
//   - Site: empty address array when address field is null
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Fhir\Mappers\OrganizationMapper;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMapperTest extends TestCase
{
    use RefreshDatabase;

    // ── Tenant Organization ───────────────────────────────────────────────────

    public function test_tenant_resource_type_is_organization(): void
    {
        $tenant = Tenant::factory()->create();
        $fhir   = OrganizationMapper::fromTenant($tenant);
        $this->assertEquals('Organization', $fhir['resourceType']);
    }

    public function test_tenant_id_has_tenant_prefix(): void
    {
        $tenant = Tenant::factory()->create();
        $fhir   = OrganizationMapper::fromTenant($tenant);
        $this->assertEquals("tenant-{$tenant->id}", $fhir['id']);
    }

    public function test_tenant_name_is_set(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Sunrise PACE']);
        $fhir   = OrganizationMapper::fromTenant($tenant);
        $this->assertEquals('Sunrise PACE', $fhir['name']);
    }

    public function test_tenant_with_cms_contract_id_has_identifier(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H1234']);
        $fhir   = OrganizationMapper::fromTenant($tenant);

        $this->assertNotEmpty($fhir['identifier']);
        $this->assertEquals('H1234', $fhir['identifier'][0]['value']);
    }

    public function test_tenant_without_cms_contract_id_has_empty_identifier(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => null]);
        $fhir   = OrganizationMapper::fromTenant($tenant);

        $this->assertEmpty($fhir['identifier']);
    }

    public function test_tenant_active_flag_reflects_model(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $fhir   = OrganizationMapper::fromTenant($tenant);
        $this->assertTrue($fhir['active']);
    }

    // ── Site Organization ─────────────────────────────────────────────────────

    public function test_site_resource_type_is_organization(): void
    {
        $site = Site::factory()->create();
        $fhir = OrganizationMapper::fromSite($site);
        $this->assertEquals('Organization', $fhir['resourceType']);
    }

    public function test_site_id_has_site_prefix(): void
    {
        $site = Site::factory()->create();
        $fhir = OrganizationMapper::fromSite($site);
        $this->assertEquals("site-{$site->id}", $fhir['id']);
    }

    public function test_site_name_is_set(): void
    {
        $site = Site::factory()->create(['name' => 'East PACE Center']);
        $fhir = OrganizationMapper::fromSite($site);
        $this->assertEquals('East PACE Center', $fhir['name']);
    }

    public function test_site_address_is_populated_from_model(): void
    {
        $site = Site::factory()->create([
            'address' => '100 Main St',
            'city'    => 'Columbus',
            'state'   => 'OH',
            'zip'     => '43215',
        ]);
        $fhir = OrganizationMapper::fromSite($site);

        $address = $fhir['address'][0];
        $this->assertContains('100 Main St', $address['line']);
        $this->assertEquals('Columbus', $address['city']);
        $this->assertEquals('OH', $address['state']);
        $this->assertEquals('43215', $address['postalCode']);
    }

    public function test_site_without_address_has_empty_address_array(): void
    {
        $site = Site::factory()->create(['address' => null]);
        $fhir = OrganizationMapper::fromSite($site);
        $this->assertEmpty($fhir['address']);
    }

    public function test_site_part_of_references_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $fhir   = OrganizationMapper::fromSite($site);

        $this->assertEquals("Organization/tenant-{$tenant->id}", $fhir['partOf']['reference']);
    }
}
