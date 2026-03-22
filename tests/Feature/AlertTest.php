<?php

namespace Tests\Feature;

use App\Events\AlertCreatedEvent;
use App\Models\Alert;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $pcUser;
    private User        $swUser;
    private User        $otherTenantUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'ALT',
        ]);
        $this->pcUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->swUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'social_work',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $otherTenant = Tenant::factory()->create();
        $this->otherTenantUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Alert listing ────────────────────────────────────────────────────────

    public function test_user_sees_alerts_for_their_department(): void
    {
        // Alert targeting primary_care
        Alert::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'severity'          => 'warning',
            'is_active'         => true,
        ]);

        // Alert targeting social_work only — should NOT appear for pcUser
        Alert::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['social_work'],
            'severity'          => 'info',
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->pcUser)
            ->getJson('/alerts');

        $response->assertOk();
        $alerts = $response->json('data') ?? $response->json();

        // PC user should only see the primary_care alert
        $this->assertCount(1, $alerts);
        $this->assertSame('warning', $alerts[0]['severity']);
    }

    public function test_unread_count_reflects_active_unacknowledged_alerts(): void
    {
        // 2 unacknowledged alerts for primary_care
        Alert::factory()->count(2)->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
            'acknowledged_at'   => null,
        ]);

        // 1 already acknowledged — should not count
        Alert::factory()->acknowledged()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
        ]);

        $this->actingAs($this->pcUser)
            ->getJson('/alerts/unread-count')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    // ─── Acknowledge ─────────────────────────────────────────────────────────

    public function test_acknowledge_alert_sets_acknowledged_at(): void
    {
        $alert = Alert::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
            'acknowledged_at'   => null,
        ]);

        $this->actingAs($this->pcUser)
            ->patchJson("/alerts/{$alert->id}/acknowledge")
            ->assertOk();

        $this->assertNotNull(Alert::find($alert->id)->acknowledged_at);
        $this->assertSame($this->pcUser->id, Alert::find($alert->id)->acknowledged_by_user_id);
    }

    public function test_acknowledge_is_idempotent(): void
    {
        $alert = Alert::factory()->acknowledged()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
        ]);

        // Second acknowledge — should still return 200 (idempotent)
        $this->actingAs($this->pcUser)
            ->patchJson("/alerts/{$alert->id}/acknowledge")
            ->assertOk();
    }

    public function test_user_outside_target_dept_cannot_acknowledge(): void
    {
        // Alert only for primary_care — sw user should get 403
        $alert = Alert::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
            'acknowledged_at'   => null,
        ]);

        $this->actingAs($this->swUser)
            ->patchJson("/alerts/{$alert->id}/acknowledge")
            ->assertStatus(403);
    }

    // ─── Resolve ─────────────────────────────────────────────────────────────

    public function test_resolve_alert_sets_is_active_false(): void
    {
        $alert = Alert::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'participant_id'    => $this->participant->id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
        ]);

        $this->actingAs($this->pcUser)
            ->patchJson("/alerts/{$alert->id}/resolve")
            ->assertOk();

        $this->assertFalse((bool) Alert::find($alert->id)->is_active);
        $this->assertNotNull(Alert::find($alert->id)->resolved_at);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_alerts_scoped_to_tenant(): void
    {
        // Create alert for another tenant — should not appear
        Alert::factory()->create([
            'tenant_id'         => $this->otherTenantUser->tenant_id,
            'target_departments'=> ['primary_care'],
            'is_active'         => true,
        ]);

        $this->actingAs($this->pcUser)
            ->getJson('/alerts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ─── Broadcast ────────────────────────────────────────────────────────────

    public function test_creating_critical_alert_broadcasts_alert_created_event(): void
    {
        Event::fake([AlertCreatedEvent::class]);

        // Admin users can create manual alerts
        $adminUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $this->actingAs($adminUser)
            ->postJson('/alerts', [
                'title'              => 'Critical System Alert',
                'message'            => 'Immediate action required.',
                'severity'           => 'critical',
                'target_departments' => ['primary_care', 'qa_compliance'],
                'participant_id'     => $this->participant->id,
            ])
            ->assertStatus(201);

        Event::assertDispatched(AlertCreatedEvent::class, function (AlertCreatedEvent $event) {
            return $event->alert->severity === 'critical'
                && $event->alert->tenant_id === $this->tenant->id;
        });
    }
}
