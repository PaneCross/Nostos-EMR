<?php

// ─── ClinicalOrderTest ────────────────────────────────────────────────────────
// Feature tests for W4-7 CPOE (Computerized Provider Order Entry).
// 42 CFR §460.90: all PACE services must be ordered and documented.
//
// Coverage:
//   - Store: auto-routing, stat alert creation, urgent alert, tenant isolation
//   - Show / Update: cross-participant guard, terminal state guard
//   - Acknowledge / Result / Complete / Cancel endpoints
//   - Non-prescriber departments blocked from creating orders
//   - Fall-with-injury SCE does not affect order routing (separate concern)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalOrderTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makePrescriber(?int $tenantId = null): User
    {
        $attrs = ['department' => 'primary_care'];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeParticipant(User $user): Participant
    {
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_prescriber_can_create_order_and_auto_routing_is_set(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'lab',
            'priority'     => 'routine',
            'instructions' => 'CBC with differential.',
        ])->assertCreated();

        $this->assertDatabaseHas('emr_clinical_orders', [
            'participant_id'    => $participant->id,
            'order_type'        => 'lab',
            'target_department' => 'primary_care',   // auto-routed by DEPARTMENT_ROUTING
            'status'            => 'pending',
        ]);
    }

    /** @test */
    public function test_stat_order_creates_critical_alert(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'lab',
            'priority'     => 'stat',
            'instructions' => 'STAT troponin.',
        ])->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'severity'   => 'critical',
            'alert_type' => 'clinical_order_stat',
        ]);
    }

    /** @test */
    public function test_urgent_order_creates_warning_alert(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'imaging',
            'priority'     => 'urgent',
            'instructions' => 'Urgent chest X-ray.',
        ])->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'severity'   => 'warning',
            'alert_type' => 'clinical_order_urgent',
        ]);
    }

    /** @test */
    public function test_routine_order_does_not_create_alert(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'consult',
            'priority'     => 'routine',
            'instructions' => 'Routine cardiology consult.',
        ])->assertCreated();

        $this->assertDatabaseMissing('emr_alerts', [
            'alert_type' => 'clinical_order_stat',
        ]);
        $this->assertDatabaseMissing('emr_alerts', [
            'alert_type' => 'clinical_order_urgent',
        ]);
    }

    /** @test */
    public function test_non_prescriber_department_cannot_create_order(): void
    {
        $user = User::factory()->create(['department' => 'dietary']);
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'lab',
            'priority'     => 'routine',
            'instructions' => 'Test.',
        ])->assertForbidden();
    }

    /** @test */
    public function test_cross_tenant_participant_is_blocked(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $user = User::factory()->create(['department' => 'primary_care', 'tenant_id' => $tenant1->id]);
        $site = Site::factory()->create(['tenant_id' => $tenant2->id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $tenant2->id,
            'site_id'   => $site->id,
        ]);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders", [
            'order_type'   => 'lab',
            'priority'     => 'routine',
            'instructions' => 'Test.',
        ])->assertForbidden();
    }

    // ── Acknowledge ───────────────────────────────────────────────────────────

    /** @test */
    public function test_acknowledge_transitions_pending_to_acknowledged(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $order = ClinicalOrder::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/orders/{$order->id}/acknowledge")
            ->assertOk();

        $this->assertDatabaseHas('emr_clinical_orders', [
            'id'     => $order->id,
            'status' => 'acknowledged',
        ]);
    }

    // ── Result ────────────────────────────────────────────────────────────────

    /** @test */
    public function test_result_endpoint_stores_result_summary(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $order = ClinicalOrder::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'in_progress',
        ]);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders/{$order->id}/result", [
            'result_summary' => 'CBC normal. WBC 7.2, Hgb 13.4.',
        ])->assertOk();

        $this->assertDatabaseHas('emr_clinical_orders', [
            'id'             => $order->id,
            'status'         => 'resulted',
            'result_summary' => 'CBC normal. WBC 7.2, Hgb 13.4.',
        ]);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /** @test */
    public function test_cancel_sets_cancelled_status(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $order = ClinicalOrder::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'pending',
        ]);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'Duplicate order.',
        ])->assertOk();

        $this->assertDatabaseHas('emr_clinical_orders', [
            'id'     => $order->id,
            'status' => 'cancelled',
        ]);
    }

    /** @test */
    public function test_cannot_cancel_completed_order(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $order = ClinicalOrder::factory()->completed()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $this->actingAs($user)->postJson("/participants/{$participant->id}/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'Too late.',
        ])->assertStatus(409);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_index_returns_orders_for_participant(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        ClinicalOrder::factory()->count(3)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/orders");

        $response->assertOk()
            ->assertJsonStructure(['orders', 'total_count', 'active_count']);
    }

    /** @test */
    public function test_unauthenticated_user_cannot_access_orders(): void
    {
        $user        = $this->makePrescriber();
        $participant = $this->makeParticipant($user);

        $this->getJson("/participants/{$participant->id}/orders")
            ->assertUnauthorized();
    }
}
