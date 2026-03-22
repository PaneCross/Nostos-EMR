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
use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessTransportStatusWebhookJob;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private Location    $pickupLoc;
    private Location    $dropoffLoc;
    private string      $webhookSecret = 'test-webhook-secret-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'WHK',
        ]);
        $user = User::factory()->create([
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

        $this->pickupLoc  = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->dropoffLoc = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        // Configure HMAC secret for webhook tests
        config(['services.transport.webhook_secret' => $this->webhookSecret]);
    }

    // ── HMAC validation ───────────────────────────────────────────────────────

    public function test_valid_hmac_signature_returns_200_and_dispatches_job(): void
    {
        Queue::fake();

        $payload = json_encode([
            'transport_trip_id' => 12345,
            'status'            => 'en_route',
        ]);

        $signature = 'sha256=' . hash_hmac('sha256', $payload, $this->webhookSecret);

        $this->postJson('/integrations/transport/status-webhook', json_decode($payload, true), [
            'X-Transport-Signature' => $signature,
            'Content-Type'          => 'application/json',
        ])->assertStatus(200)
          ->assertJson(['received' => true]);

        Queue::assertPushed(ProcessTransportStatusWebhookJob::class, function ($job) {
            return $job->transportTripId === 12345 && $job->newStatus === 'en_route';
        });
    }

    public function test_invalid_hmac_returns_403(): void
    {
        Queue::fake();

        $payload = json_encode([
            'transport_trip_id' => 12345,
            'status'            => 'en_route',
        ]);

        $this->postJson('/integrations/transport/status-webhook', json_decode($payload, true), [
            'X-Transport-Signature' => 'sha256=invalidsignature000',
            'Content-Type'          => 'application/json',
        ])->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_missing_signature_header_returns_403(): void
    {
        Queue::fake();

        $this->postJson('/integrations/transport/status-webhook', [
            'transport_trip_id' => 12345,
            'status'            => 'en_route',
        ])->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_invalid_signature_creates_audit_log_entry(): void
    {
        $payload = json_encode([
            'transport_trip_id' => 12345,
            'status'            => 'completed',
        ]);

        $this->postJson('/integrations/transport/status-webhook', json_decode($payload, true), [
            'X-Transport-Signature' => 'sha256=tampered',
        ])->assertStatus(403);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'transport_webhook.invalid_signature',
        ]);
    }

    public function test_missing_transport_secret_config_returns_403(): void
    {
        // If secret is not configured, webhook must fail closed (not open)
        config(['services.transport.webhook_secret' => null]);

        $payload = json_encode(['transport_trip_id' => 1, 'status' => 'completed']);

        $this->postJson('/integrations/transport/status-webhook', json_decode($payload, true), [
            'X-Transport-Signature' => 'sha256=anything',
        ])->assertStatus(403);
    }

    // ── Job behaviour ─────────────────────────────────────────────────────────

    public function test_webhook_no_show_creates_alert_for_primary_care(): void
    {
        // Create a bridged transport request
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'transportation']);
        $transportTripId = 55555;

        $request = TransportRequest::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'participant_id'      => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $user->id,
            'status'              => 'en_route',
            'transport_trip_id'   => $transportTripId,
        ]);

        // Dispatch job directly (bypassing queue for synchronous test)
        $job = new ProcessTransportStatusWebhookJob(
            transportTripId: $transportTripId,
            newStatus: 'no_show',
            payload: ['transport_trip_id' => $transportTripId, 'status' => 'no_show'],
        );
        $job->handle(
            app(\App\Services\TransportBridgeService::class),
            app(\App\Services\AlertService::class),
        );

        // Status updated on transport request
        $request->refresh();
        $this->assertEquals('no_show', $request->status);

        // Alert created targeting primary_care
        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'source_module'  => 'transport',
            'severity'       => 'warning',
        ]);
    }

    public function test_webhook_completed_updates_actual_times(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'transportation']);
        $transportTripId = 66666;

        $request = TransportRequest::factory()->create([
            'tenant_id'           => $this->tenant->id,
            'participant_id'      => $this->participant->id,
            'pickup_location_id'  => $this->pickupLoc->id,
            'dropoff_location_id' => $this->dropoffLoc->id,
            'requesting_user_id'  => $user->id,
            'status'              => 'en_route',
            'transport_trip_id'   => $transportTripId,
        ]);

        $actualPickup  = Carbon::now()->subMinutes(45)->toIso8601String();
        $actualDropoff = Carbon::now()->toIso8601String();

        $job = new ProcessTransportStatusWebhookJob(
            transportTripId: $transportTripId,
            newStatus: 'completed',
            payload: [
                'transport_trip_id'  => $transportTripId,
                'status'             => 'completed',
                'actual_pickup_time' => $actualPickup,
                'actual_dropoff_time' => $actualDropoff,
            ],
        );
        $job->handle(
            app(\App\Services\TransportBridgeService::class),
            app(\App\Services\AlertService::class),
        );

        $request->refresh();
        $this->assertEquals('completed', $request->status);
        $this->assertNotNull($request->actual_pickup_time);
        $this->assertNotNull($request->actual_dropoff_time);
    }
}
