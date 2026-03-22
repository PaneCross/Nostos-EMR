<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site   $site;
    private User   $transportUser;   // Transportation Team (can write)
    private User   $clinicalUser;    // Primary Care (read-only on locations)
    private User   $otherTenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'LOC']);

        $this->transportUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'transportation',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $this->clinicalUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $otherTenant = Tenant::factory()->create();
        $this->otherTenantUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'department' => 'transportation',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_list_locations(): void
    {
        Location::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->clinicalUser)
            ->getJson('/locations');

        $response->assertOk();
        $this->assertCount(3, $response->json());
    }

    public function test_locations_scoped_to_tenant(): void
    {
        // Location for this tenant
        Location::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        // Location for other tenant — should not appear
        Location::factory()->create(['tenant_id' => $this->otherTenantUser->tenant_id, 'is_active' => true]);

        $this->actingAs($this->clinicalUser)
            ->getJson('/locations')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_inactive_locations_excluded_by_default(): void
    {
        Location::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        Location::factory()->inactive()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->clinicalUser)
            ->getJson('/locations')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_include_inactive_param_returns_all(): void
    {
        Location::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        Location::factory()->inactive()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->transportUser)
            ->getJson('/locations?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_transportation_team_can_create_location(): void
    {
        $response = $this->actingAs($this->transportUser)
            ->postJson('/locations', [
                'location_type' => 'specialist',
                'name'          => 'Central Cardiology Associates',
                'street'        => '123 Main St',
                'city'          => 'Long Beach',
                'state'         => 'CA',
                'zip'           => '90802',
                'phone'         => '(562) 555-0100',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_locations', [
            'name'      => 'Central Cardiology Associates',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_non_transportation_user_cannot_create_location(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson('/locations', [
                'location_type' => 'specialist',
                'name'          => 'Test Clinic',
            ])
            ->assertStatus(403);
    }

    public function test_create_location_validates_required_fields(): void
    {
        $this->actingAs($this->transportUser)
            ->postJson('/locations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_type', 'name']);
    }

    public function test_create_location_validates_location_type_enum(): void
    {
        $this->actingAs($this->transportUser)
            ->postJson('/locations', [
                'location_type' => 'invalid_type',
                'name'          => 'Test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_type']);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_transportation_team_can_update_location(): void
    {
        $location = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->transportUser)
            ->putJson("/locations/{$location->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_locations', [
            'id'   => $location->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_non_transportation_user_cannot_update_location(): void
    {
        $location = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->clinicalUser)
            ->putJson("/locations/{$location->id}", ['name' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_cannot_update_location_from_different_tenant(): void
    {
        $otherLocation = Location::factory()->create(['tenant_id' => $this->otherTenantUser->tenant_id]);

        $this->actingAs($this->transportUser)
            ->putJson("/locations/{$otherLocation->id}", ['name' => 'X'])
            ->assertStatus(403);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_transportation_team_can_soft_delete_location(): void
    {
        $location = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->transportUser)
            ->deleteJson("/locations/{$location->id}")
            ->assertOk();

        $this->assertSoftDeleted('emr_locations', ['id' => $location->id]);
    }

    public function test_non_transportation_user_cannot_delete_location(): void
    {
        $location = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->clinicalUser)
            ->deleteJson("/locations/{$location->id}")
            ->assertStatus(403);
    }
}
