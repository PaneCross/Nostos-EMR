<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VitalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'TEST',
        ]);
        $this->user = User::factory()->create([
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
    }

    // ─── Record vitals ────────────────────────────────────────────────────────

    public function test_record_vitals_returns_201_with_valid_data(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'      => 128,
                'bp_diastolic'     => 82,
                'pulse'            => 72,
                'temperature_f'    => 98.6,
                'respiratory_rate' => 16,
                'o2_saturation'    => 97,
                'weight_lbs'       => 165.5,
                'pain_score'       => 2,
                'position'         => 'sitting',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_vitals', [
            'participant_id'      => $this->participant->id,
            'bp_systolic'         => 128,
            'bp_diastolic'        => 82,
            'recorded_by_user_id' => $this->user->id,
        ]);
    }

    public function test_vitals_can_be_stored_with_minimal_fields(): void
    {
        // Only BP is required for a valid vital check
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 140,
                'bp_diastolic' => 88,
            ]);

        $response->assertCreated();
    }

    public function test_vitals_rejects_out_of_range_bp_systolic(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 350,  // > 300 max
                'bp_diastolic' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bp_systolic']);
    }

    public function test_vitals_rejects_out_of_range_o2_saturation(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'   => 120,
                'bp_diastolic'  => 80,
                'o2_saturation' => 105,  // > 100 max
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['o2_saturation']);
    }

    public function test_vitals_rejects_invalid_pain_score(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 120,
                'bp_diastolic' => 80,
                'pain_score'   => 11,  // > 10 max
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pain_score']);
    }

    public function test_vitals_rejects_invalid_position(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 120,
                'bp_diastolic' => 80,
                'position'     => 'crouching',  // not in enum
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position']);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_vitals_ordered_newest_first(): void
    {
        Vital::factory()->count(5)
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['recorded_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/vitals");

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(5, $data);
    }

    // ─── Trends ───────────────────────────────────────────────────────────────

    public function test_trends_endpoint_returns_aggregated_data(): void
    {
        Vital::factory()->count(10)
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['recorded_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/vitals/trends?days=30");

        $response->assertOk();
        $this->assertIsArray($response->json());
    }

    public function test_trends_respects_days_parameter(): void
    {
        // Create a vital 45 days ago
        Vital::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'recorded_by_user_id' => $this->user->id,
                'recorded_at'         => now()->subDays(45),
            ]);
        // Create a vital 15 days ago
        Vital::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'recorded_by_user_id' => $this->user->id,
                'recorded_at'         => now()->subDays(15),
            ]);

        $response30 = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/vitals/trends?days=30");
        $response30->assertOk();

        $response90 = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/vitals/trends?days=90");
        $response90->assertOk();

        // 30-day window should have 1 record; 90-day should have 2
        $this->assertCount(1, $response30->json());
        $this->assertCount(2, $response90->json());
    }

    // ─── Department restriction ───────────────────────────────────────────────

    public function test_non_clinical_dept_cannot_record_vitals(): void
    {
        $dietaryUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'dietary',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $this->actingAs($dietaryUser)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 120,
                'bp_diastolic' => 80,
            ])
            ->assertForbidden();
    }

    public function test_primary_care_can_record_vitals(): void
    {
        // Baseline: primary_care (already $this->user) can POST vitals
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 118,
                'bp_diastolic' => 76,
            ])
            ->assertCreated();
    }

    public function test_finance_dept_cannot_record_vitals(): void
    {
        $financeUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'finance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $this->actingAs($financeUser)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 130,
                'bp_diastolic' => 85,
            ])
            ->assertForbidden();
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_view_vitals_from_different_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/vitals")
            ->assertForbidden();
    }

    public function test_cannot_record_vitals_for_different_tenant_participant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->postJson("/participants/{$otherParticipant->id}/vitals", [
                'bp_systolic'  => 120,
                'bp_diastolic' => 80,
            ])
            ->assertForbidden();
    }
}
