<?php

// ─── WoundCareTest ─────────────────────────────────────────────────────────────
// Feature tests for W5-1 Wound Care module.
// Coverage:
//   - Store: nursing dept can create, non-nursing blocked, cross-tenant isolated
//   - Index: returns wound list per participant
//   - Show: returns single wound with assessments
//   - AddAssessment: creates assessment, healed status_change closes wound
//   - AddAssessment on healed wound: 409
//   - Close: marks wound healed, repeated close is 409
//   - Dashboard wounds widget: PrimaryCareDashboard + HomeCareDashboard JSON structure
// ─────────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WoundCareTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeNurse(?int $tenantId = null, string $dept = 'primary_care'): User
    {
        $attrs = ['department' => $dept];
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

    private function woundPayload(array $overrides = []): array
    {
        return array_merge([
            'wound_type'          => 'pressure_injury',
            'location'            => 'Sacrum',
            'first_identified_date' => now()->subWeek()->toDateString(),
            'goal'                => 'healing',
        ], $overrides);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_nursing_dept_can_create_wound_record(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds", $this->woundPayload([
                'pressure_injury_stage' => 'stage_2',
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('emr_wound_records', [
            'participant_id'        => $participant->id,
            'wound_type'            => 'pressure_injury',
            'pressure_injury_stage' => 'stage_2',
            'status'                => 'open',
        ]);
    }

    /** @test */
    public function test_home_care_dept_can_create_wound_record(): void
    {
        $nurse       = $this->makeNurse(dept: 'home_care');
        $participant = $this->makeParticipant($nurse);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds", $this->woundPayload())
            ->assertCreated();
    }

    /** @test */
    public function test_non_nursing_dept_cannot_create_wound(): void
    {
        $financeUser = $this->makeNurse(dept: 'finance');
        $participant = $this->makeParticipant($financeUser);

        $this->actingAs($financeUser)
            ->postJson("/participants/{$participant->id}/wounds", $this->woundPayload())
            ->assertForbidden();
    }

    /** @test */
    public function test_cross_tenant_wound_creation_is_blocked(): void
    {
        $nurse         = $this->makeNurse();
        $otherTenant   = Tenant::factory()->create();
        $otherSite     = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$otherParticipant->id}/wounds", $this->woundPayload())
            ->assertForbidden();
    }

    /** @test */
    public function test_wound_creation_requires_wound_type_and_location(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds", [])
            ->assertUnprocessable();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_index_returns_wound_list_for_participant(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        WoundRecord::factory()->count(3)->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $response = $this->actingAs($nurse)
            ->getJson("/participants/{$participant->id}/wounds")
            ->assertOk();

        $this->assertCount(3, $response->json('open'));
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_show_returns_wound_with_assessments(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $response = $this->actingAs($nurse)
            ->getJson("/participants/{$participant->id}/wounds/{$wound->id}")
            ->assertOk();

        $this->assertArrayHasKey('assessments', $response->json());
    }

    // ── AddAssessment ─────────────────────────────────────────────────────────

    /** @test */
    public function test_nursing_dept_can_add_assessment_to_open_wound(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds/{$wound->id}/assess", [
                'length_cm'     => 3.0,
                'width_cm'      => 2.0,
                'status_change' => 'improved',
                'notes'         => 'Wound is improving.',
            ])
            ->assertCreated()
            ->assertJsonStructure(['assessment', 'wound']);
    }

    /** @test */
    public function test_healed_status_change_closes_wound_record(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds/{$wound->id}/assess", [
                'status_change' => 'healed',
                'notes'         => 'Wound fully healed.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_wound_records', [
            'id'     => $wound->id,
            'status' => 'healed',
        ]);
    }

    /** @test */
    public function test_adding_assessment_to_healed_wound_returns_409(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->healed()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds/{$wound->id}/assess", [
                'notes' => 'Attempted late assessment.',
            ])
            ->assertStatus(409);
    }

    // ── Close ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_nursing_dept_can_close_open_wound(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds/{$wound->id}/close", [
                'healed_date' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_wound_records', [
            'id'     => $wound->id,
            'status' => 'healed',
        ]);
    }

    /** @test */
    public function test_closing_already_healed_wound_returns_409(): void
    {
        $nurse       = $this->makeNurse();
        $participant = $this->makeParticipant($nurse);

        $wound = WoundRecord::factory()->healed()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->postJson("/participants/{$participant->id}/wounds/{$wound->id}/close")
            ->assertStatus(409);
    }

    // ── Dashboard widgets ─────────────────────────────────────────────────────

    /** @test */
    public function test_primary_care_wounds_widget_returns_correct_structure(): void
    {
        $nurse       = $this->makeNurse(dept: 'primary_care');
        $participant = $this->makeParticipant($nurse);

        WoundRecord::factory()->open()->pressureInjury('stage_3')->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->getJson('/dashboards/primary-care/wounds')
            ->assertOk()
            ->assertJsonStructure(['wounds', 'open_count', 'critical_count']);
    }

    /** @test */
    public function test_home_care_wounds_widget_requires_home_care_department(): void
    {
        $financeUser = $this->makeNurse(dept: 'finance');

        $this->actingAs($financeUser)
            ->getJson('/dashboards/home-care/wounds')
            ->assertForbidden();
    }

    /** @test */
    public function test_primary_care_wounds_widget_flags_critical_stage_wounds(): void
    {
        $nurse       = $this->makeNurse(dept: 'primary_care');
        $participant = $this->makeParticipant($nurse);

        // Create 1 critical (Stage 3) + 1 non-critical (Stage 2)
        WoundRecord::factory()->open()->pressureInjury('stage_3')->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);
        WoundRecord::factory()->open()->pressureInjury('stage_2')->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $nurse->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $response = $this->actingAs($nurse)
            ->getJson('/dashboards/primary-care/wounds')
            ->assertOk();

        $this->assertEquals(2, $response->json('open_count'));
        $this->assertEquals(1, $response->json('critical_count'));

        // The critical wound should have is_critical=true
        $criticalWounds = collect($response->json('wounds'))->where('is_critical', true);
        $this->assertCount(1, $criticalWounds);
    }
}
