<?php

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarePlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private User        $idtAdmin;
    private User        $otherUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'CP',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->idtAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'idt',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        // Different tenant — for cross-tenant isolation tests
        $otherTenant    = Tenant::factory()->create();
        $this->otherUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'department' => 'idt',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Create draft care plan ───────────────────────────────────────────────

    public function test_create_draft_care_plan_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/careplan");

        $response->assertStatus(201);

        $this->assertDatabaseHas('emr_care_plans', [
            'participant_id' => $this->participant->id,
            'status'         => 'draft',
            'version'        => 1,
        ]);
    }

    public function test_care_plan_show_returns_active_plan(): void
    {
        CarePlan::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'status'         => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/careplan");

        $response->assertOk()
            ->assertJsonFragment(['status' => 'active']);
    }

    // ─── Upsert goal ─────────────────────────────────────────────────────────

    public function test_upsert_goal_creates_domain_goal(): void
    {
        $plan = CarePlan::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'status'         => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/careplan/{$plan->id}/goals/medical", [
                'goal_description'    => 'Maintain BP below 140/90.',
                'measurable_outcomes' => 'BP readings on target 80% of visits.',
                'interventions'       => 'Monthly BP checks, medication titration.',
                'target_date'         => now()->addMonths(3)->format('Y-m-d'),
                'status'              => 'active',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('emr_care_plan_goals', [
            'care_plan_id'    => $plan->id,
            'domain'          => 'medical',
            'goal_description'=> 'Maintain BP below 140/90.',
        ]);
    }

    public function test_upsert_goal_updates_existing_goal(): void
    {
        $plan = CarePlan::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'status'         => 'draft',
        ]);

        CarePlanGoal::factory()->forDomain('nursing')->create([
            'care_plan_id' => $plan->id,
            'status'       => 'active',
        ]);

        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/careplan/{$plan->id}/goals/nursing", [
                'goal_description'    => 'Updated nursing goal.',
                'measurable_outcomes' => 'Updated outcomes.',
                'interventions'       => 'Updated interventions.',
                'target_date'         => now()->addMonths(4)->format('Y-m-d'),
                'status'              => 'modified',
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_care_plan_goals', [
            'care_plan_id' => $plan->id,
            'domain'       => 'nursing',
            'status'       => 'modified',
        ]);
        // Should still have exactly one nursing goal (updated, not duplicated)
        $this->assertSame(1, CarePlanGoal::where('care_plan_id', $plan->id)->where('domain', 'nursing')->count());
    }

    // ─── Approve ─────────────────────────────────────────────────────────────

    public function test_idt_admin_can_approve_care_plan(): void
    {
        $plan = CarePlan::factory()->draft()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->idtAdmin)
            ->postJson("/participants/{$this->participant->id}/careplan/{$plan->id}/approve");

        $response->assertOk();

        $this->assertDatabaseHas('emr_care_plans', [
            'id'                   => $plan->id,
            'status'               => 'active',
            'approved_by_user_id'  => $this->idtAdmin->id,
        ]);
    }

    public function test_non_admin_cannot_approve_care_plan(): void
    {
        $plan = CarePlan::factory()->draft()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->actingAs($this->user)   // standard role — not admin
            ->postJson("/participants/{$this->participant->id}/careplan/{$plan->id}/approve")
            ->assertStatus(403);
    }

    // ─── New version ──────────────────────────────────────────────────────────

    public function test_new_version_archives_active_plan_and_increments_version(): void
    {
        $plan = CarePlan::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'status'         => 'active',
            'version'        => 1,
        ]);

        $response = $this->actingAs($this->idtAdmin)
            ->postJson("/participants/{$this->participant->id}/careplan/{$plan->id}/new-version");

        $response->assertStatus(201);

        // Original plan should be archived
        $this->assertDatabaseHas('emr_care_plans', ['id' => $plan->id, 'status' => 'archived']);

        // New version should exist with version=2 and status=draft
        $this->assertDatabaseHas('emr_care_plans', [
            'participant_id' => $this->participant->id,
            'version'        => 2,
            'status'         => 'draft',
        ]);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_access_care_plan_of_different_tenant(): void
    {
        $this->actingAs($this->otherUser)
            ->getJson("/participants/{$this->participant->id}/careplan")
            ->assertStatus(403);
    }
}
