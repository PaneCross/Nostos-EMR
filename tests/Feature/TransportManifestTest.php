<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TransportRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportManifestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $transportUser;
    private User        $clinician;
    private Participant $participant;
    private Location    $pickupLoc;
    private Location    $dropoffLoc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'MAN',
        ]);
        $this->transportUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'transportation',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->clinician = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $this->pickupLoc  = Location::factory()->create(['tenant_id' => $this->tenant->id, 'location_type' => 'pace_center']);
        $this->dropoffLoc = Location::factory()->create(['tenant_id' => $this->tenant->id, 'location_type' => 'specialist']);
    }

    // ── Manifest page ─────────────────────────────────────────────────────────

    public function test_manifest_page_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->transportUser)
            ->get('/transport/manifest')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Transport/Manifest'));
    }

    public function test_manifest_page_requires_auth(): void
    {
        $this->get('/transport/manifest')
            ->assertRedirect('/login');
    }

    // ── Runs endpoint ─────────────────────────────────────────────────────────

    public function test_runs_returns_trips_for_requested_date(): void
    {
        $today   = Carbon::today()->toDateString();
        $pickup  = Carbon::today()->setHour(9);

        TransportRequest::factory()->create([
            'tenant_id'              => $this->tenant->id,
            'participant_id'         => $this->participant->id,
            'pickup_location_id'     => $this->pickupLoc->id,
            'dropoff_location_id'    => $this->dropoffLoc->id,
            'requesting_user_id'     => $this->transportUser->id,
            'requested_pickup_time'  => $pickup,
            'scheduled_pickup_time'  => $pickup,
            'status'                 => 'scheduled',
            'trip_type'              => 'to_center',
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson("/transport/manifest/runs?date={$today}&site_id={$this->site->id}");

        $response->assertStatus(200);
        $data = $response->json('data') ?? $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('scheduled', $data[0]['status']);
    }

    public function test_runs_empty_for_wrong_date(): void
    {
        $pickup = Carbon::today()->setHour(9);

        TransportRequest::factory()->create([
            'tenant_id'              => $this->tenant->id,
            'participant_id'         => $this->participant->id,
            'pickup_location_id'     => $this->pickupLoc->id,
            'dropoff_location_id'    => $this->dropoffLoc->id,
            'requesting_user_id'     => $this->transportUser->id,
            'requested_pickup_time'  => $pickup,
            'status'                 => 'scheduled',
            'trip_type'              => 'to_center',
        ]);

        // Query a different date — should return empty
        $wrongDate = Carbon::yesterday()->toDateString();
        $response = $this->actingAs($this->transportUser)
            ->getJson("/transport/manifest/runs?date={$wrongDate}&site_id={$this->site->id}");

        $data = $response->json('data') ?? $response->json();
        $this->assertEmpty($data);
    }

    public function test_runs_includes_mobility_flags_from_snapshot(): void
    {
        $flags = [
            ['type' => 'wheelchair', 'severity' => 'standard', 'description' => 'Manual chair'],
        ];

        $pickup = Carbon::today()->setHour(8);

        TransportRequest::factory()->create([
            'tenant_id'              => $this->tenant->id,
            'participant_id'         => $this->participant->id,
            'pickup_location_id'     => $this->pickupLoc->id,
            'dropoff_location_id'    => $this->dropoffLoc->id,
            'requesting_user_id'     => $this->transportUser->id,
            'requested_pickup_time'  => $pickup,
            'status'                 => 'scheduled',
            'trip_type'              => 'to_center',
            'mobility_flags_snapshot' => $flags,
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson('/transport/manifest/runs?date=' . Carbon::today()->toDateString() . "&site_id={$this->site->id}");

        $data = $response->json('data') ?? $response->json();
        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data[0]['mobility_flags']);
        $this->assertEquals('wheelchair', $data[0]['mobility_flags'][0]['flag_type']);
    }

    public function test_runs_excludes_cancelled_trips(): void
    {
        $pickup = Carbon::today()->setHour(10);

        // One cancelled trip (should be excluded)
        TransportRequest::factory()->cancelled()->create([
            'tenant_id'              => $this->tenant->id,
            'participant_id'         => $this->participant->id,
            'pickup_location_id'     => $this->pickupLoc->id,
            'dropoff_location_id'    => $this->dropoffLoc->id,
            'requesting_user_id'     => $this->transportUser->id,
            'requested_pickup_time'  => $pickup,
        ]);

        // One active trip (should be included)
        TransportRequest::factory()->create([
            'tenant_id'              => $this->tenant->id,
            'participant_id'         => $this->participant->id,
            'pickup_location_id'     => $this->pickupLoc->id,
            'dropoff_location_id'    => $this->dropoffLoc->id,
            'requesting_user_id'     => $this->transportUser->id,
            'requested_pickup_time'  => $pickup->copy()->addHour(),
            'status'                 => 'scheduled',
            'trip_type'              => 'from_center',
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson('/transport/manifest/runs?date=' . Carbon::today()->toDateString() . "&site_id={$this->site->id}");

        $data = $response->json('data') ?? $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('scheduled', $data[0]['status']);
    }

    public function test_runs_is_scoped_to_tenant(): void
    {
        // Another tenant's transport request
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $otherParticipant = Participant::factory()->enrolled()->forTenant($otherTenant->id)->forSite($otherSite->id)->create();
        $otherUser        = User::factory()->create(['tenant_id' => $otherTenant->id, 'department' => 'transportation', 'is_active' => true]);
        $otherPickup      = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherDropoff     = Location::factory()->create(['tenant_id' => $otherTenant->id]);

        TransportRequest::factory()->create([
            'tenant_id'              => $otherTenant->id,
            'participant_id'         => $otherParticipant->id,
            'pickup_location_id'     => $otherPickup->id,
            'dropoff_location_id'    => $otherDropoff->id,
            'requesting_user_id'     => $otherUser->id,
            'requested_pickup_time'  => Carbon::today()->setHour(9),
            'status'                 => 'scheduled',
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson('/transport/manifest/runs?date=' . Carbon::today()->toDateString() . "&site_id={$this->site->id}");

        $data = $response->json('data') ?? $response->json();
        $this->assertEmpty($data); // Own tenant has no requests today
    }
}
