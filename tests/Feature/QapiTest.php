<?php

// ─── QapiTest ─────────────────────────────────────────────────────────────────
// Feature tests for W4-6 QAPI (Quality Assessment and Performance Improvement)
// project management. 42 CFR §460.136–§460.140.
//
// Coverage:
//   - Index: Inertia page renders with projects, active_count, meets_minimum
//   - Store: QA admin creates project; non-QA returns 403
//   - Show: tenant-scoped JSON; cross-tenant 403
//   - Update: QA admin updates status, metrics, actual_completion_date auto-set
//   - Remeasure: advances active project to remeasuring; rejects non-active
//   - Access control: qa_compliance and it_admin can write; others cannot
//   - QAPI KPI in QA Dashboard: active_qapi_count
//   - QapiProject model: isActive(), isCompleted(), scopeActive()
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\QapiProject;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QapiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept, ?int $tenantId = null): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeQaUser(?int $tenantId = null): User
    {
        return $this->makeUser('qa_compliance', $tenantId);
    }

    private function projectPayload(array $overrides = []): array
    {
        return array_merge([
            'title'                  => 'Reduce Fall Incidents',
            'description'            => 'QI project to reduce participant falls.',
            'aim_statement'          => 'Reduce fall rate by 20% within 6 months.',
            'domain'                 => 'safety',
            'start_date'             => now()->toDateString(),
            'target_completion_date' => now()->addMonths(6)->toDateString(),
            'baseline_metric'        => '42% falls in Q3',
            'target_metric'          => 'Reduce to <30%',
        ], $overrides);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_index_returns_inertia_page_with_projects(): void
    {
        $user = $this->makeQaUser();
        QapiProject::factory()->count(3)->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->get('/qapi/projects');
        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->component('Qapi/Projects')
                     ->has('projects', 3)
                     ->has('active_count')
                     ->has('meets_minimum')
                     ->has('min_required')
                     ->has('statuses')
                     ->has('domains')
            );
    }

    /** @test */
    public function test_index_reports_meets_minimum_when_2_active(): void
    {
        $user = $this->makeQaUser();
        QapiProject::factory()->count(2)->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($user)->get('/qapi/projects');
        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->where('meets_minimum', true)
                     ->where('active_count', 2)
            );
    }

    /** @test */
    public function test_index_reports_does_not_meet_minimum_when_1_active(): void
    {
        $user = $this->makeQaUser();
        QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($user)->get('/qapi/projects');
        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->where('meets_minimum', false)
            );
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_qa_admin_can_create_project(): void
    {
        $user = $this->makeQaUser();

        $response = $this->actingAs($user)->postJson('/qapi/projects', $this->projectPayload());
        $response->assertStatus(201)
            ->assertJsonPath('title', 'Reduce Fall Incidents')
            ->assertJsonPath('status', 'planning');

        $this->assertDatabaseHas('emr_qapi_projects', [
            'tenant_id' => $user->tenant_id,
            'title'     => 'Reduce Fall Incidents',
            'domain'    => 'safety',
        ]);
    }

    /** @test */
    public function test_non_qa_cannot_create_project(): void
    {
        $user = $this->makeUser('primary_care');

        $response = $this->actingAs($user)->postJson('/qapi/projects', $this->projectPayload());
        $response->assertForbidden();
    }

    /** @test */
    public function test_it_admin_can_create_project(): void
    {
        $user = $this->makeUser('it_admin');

        $response = $this->actingAs($user)->postJson('/qapi/projects', $this->projectPayload());
        $response->assertStatus(201);
    }

    /** @test */
    public function test_store_requires_title_and_domain(): void
    {
        $user = $this->makeQaUser();

        $response = $this->actingAs($user)->postJson('/qapi/projects', [
            'start_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'domain']);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /** @test */
    public function test_show_returns_project_json(): void
    {
        $user    = $this->makeQaUser();
        $project = QapiProject::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->getJson("/qapi/projects/{$project->id}");
        $response->assertOk()
            ->assertJsonPath('id', $project->id)
            ->assertJsonPath('title', $project->title);
    }

    /** @test */
    public function test_show_is_tenant_scoped(): void
    {
        $user          = $this->makeQaUser();
        $otherTenant   = Tenant::factory()->create();
        $otherProject  = QapiProject::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->getJson("/qapi/projects/{$otherProject->id}");
        $response->assertNotFound();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /** @test */
    public function test_qa_admin_can_update_project(): void
    {
        $user    = $this->makeQaUser();
        $project = QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($user)->patchJson("/qapi/projects/{$project->id}", [
            'current_metric' => 'Fall rate now at 35% - improvement noted.',
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('emr_qapi_projects', [
            'id'             => $project->id,
            'current_metric' => 'Fall rate now at 35% - improvement noted.',
        ]);
    }

    /** @test */
    public function test_update_auto_sets_actual_completion_date(): void
    {
        $user    = $this->makeQaUser();
        $project = QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'remeasuring',
        ]);

        $this->actingAs($user)->patchJson("/qapi/projects/{$project->id}", [
            'status' => 'completed',
        ])->assertOk();

        $this->assertNotNull($project->fresh()->actual_completion_date);
    }

    // ── Remeasure ─────────────────────────────────────────────────────────────

    /** @test */
    public function test_remeasure_advances_active_project(): void
    {
        $user    = $this->makeQaUser();
        $project = QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'active',
        ]);

        $this->actingAs($user)->postJson("/qapi/projects/{$project->id}/remeasure", [
            'current_metric' => 'Fall rate down to 38%.',
        ])->assertOk()
          ->assertJsonPath('status', 'remeasuring');
    }

    /** @test */
    public function test_remeasure_rejects_non_active_project(): void
    {
        $user    = $this->makeQaUser();
        $project = QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'planning',
        ]);

        $this->actingAs($user)->postJson("/qapi/projects/{$project->id}/remeasure", [])
            ->assertStatus(422);
    }

    // ── QA Dashboard QAPI KPI ─────────────────────────────────────────────────

    /** @test */
    public function test_qa_dashboard_includes_active_qapi_count(): void
    {
        $user = $this->makeQaUser();
        QapiProject::factory()->count(2)->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'active',
        ]);
        QapiProject::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/qa/dashboard');
        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->has('kpis.active_qapi_count')
                     ->where('kpis.active_qapi_count', 2)
            );
    }

    // ── Model helpers ─────────────────────────────────────────────────────────

    /** @test */
    public function test_qapi_project_is_active_for_planning_active_remeasuring(): void
    {
        $user = $this->makeQaUser();

        foreach (['planning', 'active', 'remeasuring'] as $status) {
            $project = QapiProject::factory()->create([
                'tenant_id' => $user->tenant_id,
                'status'    => $status,
            ]);
            $this->assertTrue($project->isActive(), "Status '{$status}' should count as active for CMS minimum");
        }
    }

    /** @test */
    public function test_qapi_project_scope_active_returns_correct_statuses(): void
    {
        $user = $this->makeQaUser();

        QapiProject::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'active']);
        QapiProject::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'planning']);
        QapiProject::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'remeasuring']);
        QapiProject::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'completed']);
        QapiProject::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'suspended']);

        $count = QapiProject::forTenant($user->tenant_id)->active()->count();
        $this->assertEquals(3, $count); // planning + active + remeasuring
    }
}
