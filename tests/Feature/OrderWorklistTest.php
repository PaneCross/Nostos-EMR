<?php

// ─── OrderWorklistTest ────────────────────────────────────────────────────────
// Feature tests for the cross-participant CPOE order worklist (GET /orders).
// 42 CFR §460.90: order worklist is the primary CPOE workflow view.
//
// Coverage:
//   - Inertia page rendered for authorized departments
//   - Dept filtering: pharmacy only sees pharmacy orders by default
//   - primary_care / it_admin / super_admin see ALL active orders
//   - Non-authenticated users are redirected
//   - Response structure includes orders, allCount, pending, userDept
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderWorklistTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $dept): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $user): Participant
    {
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);
    }

    /** @test */
    public function test_worklist_renders_inertia_page_for_prescriber(): void
    {
        $user = $this->makeUser('primary_care');

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clinical/Orders'));
    }

    /** @test */
    public function test_worklist_inertia_props_structure(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        ClinicalOrder::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'target_department' => 'primary_care',
            'status'            => 'pending',
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders')
                ->has('allCount')
                ->has('pending')
                ->has('userDept')
            );
    }

    /** @test */
    public function test_pharmacy_user_sees_only_pharmacy_orders(): void
    {
        $user        = $this->makeUser('pharmacy');
        $participant = $this->makeParticipant($user);

        // Create one pharmacy order and one primary_care order
        ClinicalOrder::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'target_department' => 'pharmacy',
            'status'            => 'pending',
        ]);
        ClinicalOrder::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'target_department' => 'primary_care',
            'status'            => 'pending',
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.0.target_department', 'pharmacy')
                ->where('orders', fn ($orders) => count($orders) === 1)
            );
    }

    /** @test */
    public function test_primary_care_user_sees_all_active_orders(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        // Create orders in different depts
        ClinicalOrder::factory()->create([
            'tenant_id' => $user->tenant_id, 'participant_id' => $participant->id,
            'target_department' => 'pharmacy', 'status' => 'pending',
        ]);
        ClinicalOrder::factory()->create([
            'tenant_id' => $user->tenant_id, 'participant_id' => $participant->id,
            'target_department' => 'primary_care', 'status' => 'pending',
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders', fn ($orders) => count($orders) >= 2)
            );
    }

    /** @test */
    public function test_worklist_requires_authentication(): void
    {
        $this->get('/orders')->assertRedirect('/login');
    }

    /** @test */
    public function test_cancelled_orders_excluded_from_worklist(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        ClinicalOrder::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'target_department' => 'primary_care',
            'status'            => 'cancelled',
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders', fn ($orders) => count($orders) === 0)
            );
    }

    /** @test */
    public function test_allCount_includes_all_tenant_active_orders(): void
    {
        $user        = $this->makeUser('pharmacy');
        $participant = $this->makeParticipant($user);

        // Create 3 orders in different depts
        ClinicalOrder::factory()->count(3)->create([
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'status'            => 'pending',
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allCount', 3)
            );
    }

    /** @test */
    public function test_tenant_isolation_worklist_does_not_show_other_tenant_orders(): void
    {
        $user1 = $this->makeUser('primary_care');
        $user2 = User::factory()->create(['department' => 'primary_care']);

        $site1 = Site::factory()->create(['tenant_id' => $user1->tenant_id]);
        $site2 = Site::factory()->create(['tenant_id' => $user2->tenant_id]);

        $p1 = Participant::factory()->create(['tenant_id' => $user1->tenant_id, 'site_id' => $site1->id]);
        $p2 = Participant::factory()->create(['tenant_id' => $user2->tenant_id, 'site_id' => $site2->id]);

        ClinicalOrder::factory()->create([
            'tenant_id' => $user1->tenant_id, 'participant_id' => $p1->id, 'status' => 'pending',
        ]);
        ClinicalOrder::factory()->create([
            'tenant_id' => $user2->tenant_id, 'participant_id' => $p2->id, 'status' => 'pending',
        ]);

        $this->actingAs($user1)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allCount', 1)
            );
    }
}
