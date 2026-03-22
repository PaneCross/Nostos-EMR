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

class TransportRequestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $clinician;
    private User        $transportUser;
    private Participant $participant;
    private Location    $pickupLoc;
    private Location    $dropoffLoc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'TRQ',
        ]);
        $this->clinician = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->transportUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'transportation',
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

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_submit_transport_request(): void
    {
        $pickup = Carbon::tomorrow()->setHour(9)->setMinute(0);

        $response = $this->actingAs($this->clinician)
            ->postJson('/transport/add-ons', [
                'participant_id'        => $this->participant->id,
                'trip_type'             => 'add_on',
                'pickup_location_id'    => $this->pickupLoc->id,
                'dropoff_location_id'   => $this->dropoffLoc->id,
                'requested_pickup_time' => $pickup->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_transport_requests', [
            'participant_id'       => $this->participant->id,
            'trip_type'            => 'add_on',
            'status'               => 'requested',
            'requesting_user_id'   => $this->clinician->id,
            'requesting_department' => 'primary_care',
        ]);
    }

    public function test_mobility_flags_snapshot_captured_at_request_time(): void
    {
        // Seed an active transport flag on the participant
        \DB::table('emr_participant_flags')->insert([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'wheelchair',
            'severity'           => 'medium',
            'description'        => 'Uses manual wheelchair',
            'is_active'          => true,
            'created_by_user_id' => $this->clinician->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $pickup = Carbon::tomorrow()->setHour(9)->setMinute(0);

        $response = $this->actingAs($this->clinician)
            ->postJson('/transport/add-ons', [
                'participant_id'        => $this->participant->id,
                'trip_type'             => 'add_on',
                'pickup_location_id'    => $this->pickupLoc->id,
                'dropoff_location_id'   => $this->dropoffLoc->id,
                'requested_pickup_time' => $pickup->toIso8601String(),
            ]);

        $response->assertStatus(201);

        $request = TransportRequest::where('participant_id', $this->participant->id)->first();
        $this->assertNotNull($request);
        // Snapshot must include the wheelchair flag that was active at request time
        $snapshot = $request->mobility_flags_snapshot;
        $this->assertNotEmpty($snapshot);
        $flagTypes = array_column($snapshot, 'type');
        $this->assertContains('wheelchair', $flagTypes);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->clinician)
            ->postJson('/transport/add-ons', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['participant_id', 'pickup_location_id', 'dropoff_location_id', 'requested_pickup_time']);
    }

    public function test_add_on_request_creates_alert_for_transportation_dept(): void
    {
        $pickup = Carbon::tomorrow()->setHour(10)->setMinute(0);

        $this->actingAs($this->clinician)
            ->postJson('/transport/add-ons', [
                'participant_id'        => $this->participant->id,
                'trip_type'             => 'add_on',
                'pickup_location_id'    => $this->pickupLoc->id,
                'dropoff_location_id'   => $this->dropoffLoc->id,
                'requested_pickup_time' => $pickup->toIso8601String(),
            ])
            ->assertStatus(201);

        // An alert should be created targeting the transportation department
        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'source_module'  => 'transport',
            'alert_type'     => 'info',
        ]);
    }

    // ── Update (Transportation Team scheduling) ────────────────────────────────

    public function test_transportation_team_can_schedule_transport_request(): void
    {
        $request = TransportRequest::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->clinician->id,
            'status'         => 'requested',
            'trip_type'      => 'add_on',
        ]);

        $scheduledTime = Carbon::tomorrow()->setHour(10)->setMinute(30);

        $response = $this->actingAs($this->transportUser)
            ->putJson("/transport/add-ons/{$request->id}", [
                'status'                => 'scheduled',
                'scheduled_pickup_time' => $scheduledTime->toIso8601String(),
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('emr_transport_requests', [
            'id'     => $request->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_non_transport_user_cannot_schedule_add_on(): void
    {
        $request = TransportRequest::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->clinician->id,
            'status'         => 'requested',
        ]);

        $this->actingAs($this->clinician)
            ->putJson("/transport/add-ons/{$request->id}", ['status' => 'scheduled'])
            ->assertStatus(403);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_requesting_user_can_cancel_their_own_request(): void
    {
        $request = TransportRequest::factory()->create([
            'tenant_id'             => $this->tenant->id,
            'participant_id'        => $this->participant->id,
            'pickup_location_id'    => $this->pickupLoc->id,
            'dropoff_location_id'   => $this->dropoffLoc->id,
            'requesting_user_id'    => $this->clinician->id,
            'requesting_department' => 'primary_care', // Must match clinician's department
            'status'                => 'requested',
        ]);

        $this->actingAs($this->clinician)
            ->postJson("/transport/add-ons/{$request->id}/cancel", [
                'cancellation_reason' => 'Appointment rescheduled',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_transport_requests', [
            'id'     => $request->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_transportation_team_can_cancel_any_request(): void
    {
        $request = TransportRequest::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->clinician->id,
            'status'         => 'scheduled',
        ]);

        $this->actingAs($this->transportUser)
            ->postJson("/transport/add-ons/{$request->id}/cancel", [
                'cancellation_reason' => 'No driver available',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_transport_requests', [
            'id'     => $request->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_completed_request_cannot_be_cancelled(): void
    {
        $request = TransportRequest::factory()->completed()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->transportUser->id,
        ]);

        $this->actingAs($this->transportUser)
            ->postJson("/transport/add-ons/{$request->id}/cancel", [
                'cancellation_reason' => 'Already done',
            ])
            ->assertStatus(409);
    }

    // ── Pending queue ─────────────────────────────────────────────────────────

    public function test_pending_endpoint_returns_add_on_requests(): void
    {
        TransportRequest::factory()->count(2)->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->clinician->id,
            'trip_type'      => 'add_on',
            'status'         => 'requested',
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson('/transport/add-ons/pending');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data') ?? $response->json());
    }

    public function test_pending_endpoint_excludes_non_addon_requests(): void
    {
        // Regular (non-add_on) scheduled trips
        TransportRequest::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $this->clinician->id,
            'trip_type'      => 'to_center',
            'status'         => 'requested',
        ]);

        $response = $this->actingAs($this->transportUser)
            ->getJson('/transport/add-ons/pending');

        $data = $response->json('data') ?? $response->json();
        $this->assertEmpty($data);
    }
}
