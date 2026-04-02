<?php

// ─── LabResultViewerTest ──────────────────────────────────────────────────────
// Feature tests for W5-2 Lab Results Viewer module.
// Coverage:
//   - Index: paginated list, filters (abnormal_only, unreviewed, date range)
//   - Index: tenant isolation (cross-tenant blocked)
//   - Store: clinical depts can create, non-clinical blocked (403)
//   - Store: manual entry triggers alert when abnormal/critical
//   - Store: validates required fields (422)
//   - Show: returns lab result with components
//   - Review: marks result reviewed, sets reviewed_by_user_id + reviewed_at
//   - Review: 409 on already-reviewed result
//   - Review: non-review dept blocked (403)
//   - Primary Care dashboard widget: unreviewed abnormal labs
//   - Primary Care dashboard widget: non-primary_care blocked (403)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabResultViewerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'primary_care', ?int $tenantId = null): User
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

    private function makeLabResult(Participant $p, User $u, array $overrides = []): LabResult
    {
        return LabResult::factory()->create(array_merge([
            'participant_id' => $p->id,
            'tenant_id'      => $u->tenant_id,
            'source'         => 'manual_entry',
            'collected_at'   => now()->subDay(),
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_lab_results(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        LabResult::factory()->count(3)->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'collected_at'   => now()->subDays(2),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/lab-results")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_index_filter_abnormal_only(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $this->makeLabResult($participant, $user, ['abnormal_flag' => true]);
        $this->makeLabResult($participant, $user, ['abnormal_flag' => true]);
        $this->makeLabResult($participant, $user, ['abnormal_flag' => false]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/lab-results?abnormal_only=1")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filter_unreviewed(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Abnormal + unreviewed
        $this->makeLabResult($participant, $user, ['abnormal_flag' => true]);
        // Abnormal + reviewed
        $this->makeLabResult($participant, $user, [
            'abnormal_flag'      => true,
            'reviewed_by_user_id'=> $user->id,
            'reviewed_at'        => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/lab-results?unreviewed=1")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_reviewed'));
    }

    public function test_index_tenant_isolation_blocks_cross_tenant(): void
    {
        $user         = $this->makeUser();
        $otherTenant  = Tenant::factory()->create();
        $otherSite    = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $this->actingAs($user)
            ->getJson("/participants/{$otherParticipant->id}/lab-results")
            ->assertNotFound();
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function test_show_returns_lab_result_with_components(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $lab = $this->makeLabResult($participant, $user, ['test_name' => 'CBC']);
        LabResultComponent::create([
            'lab_result_id'  => $lab->id,
            'component_name' => 'Hemoglobin',
            'value'          => '8.2',
            'unit'           => 'g/dL',
            'reference_range'=> '12.0-16.0',
            'abnormal_flag'  => 'low',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/lab-results/{$lab->id}")
            ->assertOk();

        $this->assertArrayHasKey('components', $response->json());
        $this->assertCount(1, $response->json('components'));
        $this->assertEquals('Hemoglobin', $response->json('components.0.component_name'));
        $this->assertEquals('low', $response->json('components.0.abnormal_flag'));
        $this->assertTrue($response->json('components.0.is_abnormal'));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_primary_care_can_store_manual_lab_result(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results", [
                'test_name'    => 'Hemoglobin A1c',
                'test_code'    => '4548-4',
                'collected_at' => now()->subDay()->toDateTimeString(),
                'components'   => [
                    ['component_name' => 'HbA1c', 'value' => '7.2', 'unit' => '%', 'abnormal_flag' => 'high'],
                ],
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'test_name', 'components']);

        $this->assertDatabaseHas('emr_lab_results', [
            'participant_id' => $participant->id,
            'test_name'      => 'Hemoglobin A1c',
            'source'         => 'manual_entry',
            'abnormal_flag'  => true,
        ]);

        $this->assertDatabaseHas('emr_lab_result_components', [
            'component_name' => 'HbA1c',
            'abnormal_flag'  => 'high',
        ]);
    }

    public function test_store_creates_critical_alert_for_critical_value(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results", [
                'test_name'    => 'Basic Metabolic Panel',
                'collected_at' => now()->subHour()->toDateTimeString(),
                'components'   => [
                    ['component_name' => 'Potassium', 'value' => '2.8', 'unit' => 'mEq/L', 'abnormal_flag' => 'critical_low'],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'abnormal_lab',
            'severity'       => 'critical',
        ]);
    }

    public function test_activities_dept_cannot_store_lab_result(): void
    {
        $user        = $this->makeUser('activities');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results", [
                'test_name'    => 'CBC',
                'collected_at' => now()->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    public function test_store_validates_required_fields(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['test_name', 'collected_at']);
    }

    // ── Review ────────────────────────────────────────────────────────────────

    public function test_primary_care_can_review_unreviewed_lab(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $lab = $this->makeLabResult($participant, $user, ['abnormal_flag' => true]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results/{$lab->id}/review")
            ->assertOk()
            ->assertJsonPath('is_reviewed', true);

        $this->assertDatabaseHas('emr_lab_results', [
            'id'                  => $lab->id,
            'reviewed_by_user_id' => $user->id,
        ]);
        $this->assertNotNull(LabResult::find($lab->id)->reviewed_at);
    }

    public function test_review_returns_409_when_already_reviewed(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $lab = $this->makeLabResult($participant, $user, [
            'abnormal_flag'       => true,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at'         => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results/{$lab->id}/review")
            ->assertStatus(409);
    }

    public function test_finance_dept_cannot_mark_lab_reviewed(): void
    {
        $user        = $this->makeUser('finance');
        $participant = $this->makeParticipant($user);

        $lab = $this->makeLabResult($participant, $user, ['abnormal_flag' => true]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/lab-results/{$lab->id}/review")
            ->assertForbidden();
    }

    // ── Primary Care dashboard widget ─────────────────────────────────────────

    public function test_primary_care_lab_results_widget_returns_correct_structure(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        LabResult::factory()->abnormal()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'collected_at'   => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/lab-results')
            ->assertOk()
            ->assertJsonStructure(['labs', 'unreviewed_count', 'critical_count']);
    }

    public function test_lab_results_widget_blocked_for_non_primary_care(): void
    {
        $user = $this->makeUser('finance');

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/lab-results')
            ->assertForbidden();
    }

    public function test_lab_results_widget_counts_only_unreviewed_abnormal(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        // 2 unreviewed abnormal
        LabResult::factory()->count(2)->abnormal()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'collected_at'   => now()->subDay(),
        ]);
        // 1 reviewed abnormal — should NOT count
        LabResult::factory()->abnormal()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'collected_at'        => now()->subDay(),
            'reviewed_by_user_id' => $user->id,
            'reviewed_at'         => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/dashboards/primary-care/lab-results')
            ->assertOk();

        $this->assertEquals(2, $response->json('unreviewed_count'));
        $this->assertCount(2, $response->json('labs'));
    }
}
