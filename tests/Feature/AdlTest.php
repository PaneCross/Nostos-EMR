<?php

namespace Tests\Feature;

use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdlTest extends TestCase
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

    // ─── Record ADL ───────────────────────────────────────────────────────────

    public function test_record_adl_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'     => 'bathing',
                'independence_level' => 'supervision',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_adl_records', [
            'participant_id'      => $this->participant->id,
            'adl_category'        => 'bathing',
            'independence_level'  => 'supervision',
            'recorded_by_user_id' => $this->user->id,
        ]);
    }

    public function test_record_adl_requires_valid_category(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'flying',
                'independence_level' => 'independent',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adl_category']);
    }

    public function test_record_adl_requires_valid_independence_level(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'bathing',
                'independence_level' => 'not_a_level',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['independence_level']);
    }

    // ─── Threshold breach ─────────────────────────────────────────────────────

    public function test_adl_threshold_breach_sets_threshold_breached_true(): void
    {
        // Set threshold to 'limited_assist' (index 2)
        AdlThreshold::create([
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'bathing',
            'threshold_level' => 'limited_assist',
            'set_by_user_id'  => $this->user->id,
            'set_at'          => now(),
        ]);

        // Record 'extensive_assist' (index 3) — worse than threshold → breach
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'bathing',
                'independence_level' => 'extensive_assist',
            ]);

        $response->assertCreated();
        $recordId = $response->json('id');

        $this->assertDatabaseHas('emr_adl_records', [
            'id'                 => $recordId,
            'threshold_breached' => true,
        ]);
    }

    public function test_no_breach_when_level_meets_threshold(): void
    {
        // Threshold: limited_assist (index 2)
        AdlThreshold::create([
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'dressing',
            'threshold_level' => 'limited_assist',
            'set_by_user_id'  => $this->user->id,
            'set_at'          => now(),
        ]);

        // Record 'limited_assist' (index 2) — equal to threshold → NO breach
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'dressing',
                'independence_level' => 'limited_assist',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_adl_records', [
            'id'                 => $response->json('id'),
            'threshold_breached' => false,
        ]);
    }

    public function test_no_breach_when_level_better_than_threshold(): void
    {
        // Threshold: extensive_assist (index 3)
        AdlThreshold::create([
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'eating',
            'threshold_level' => 'extensive_assist',
            'set_by_user_id'  => $this->user->id,
            'set_at'          => now(),
        ]);

        // Record 'supervision' (index 1) — better than threshold → NO breach
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'eating',
                'independence_level' => 'supervision',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_adl_records', [
            'id'                 => $response->json('id'),
            'threshold_breached' => false,
        ]);
    }

    public function test_no_breach_when_no_threshold_set(): void
    {
        // No threshold for this category
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'communication',
                'independence_level' => 'total_dependent',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_adl_records', [
            'id'                 => $response->json('id'),
            'threshold_breached' => false,
        ]);
    }

    public function test_adl_threshold_breach_creates_alert_for_primary_care_and_social_work(): void
    {
        AdlThreshold::create([
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'transferring',
            'threshold_level' => 'supervision',
            'set_by_user_id'  => $this->user->id,
            'set_at'          => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'transferring',
                'independence_level' => 'total_dependent',
            ])
            ->assertCreated();

        // Alert must be created targeting primary_care + social_work departments
        $alert = \App\Models\Alert::where('participant_id', $this->participant->id)
            ->where('alert_type', 'adl_decline')
            ->first();

        $this->assertNotNull($alert, 'An emr_alert must be created after ADL threshold breach.');
        $this->assertContains('primary_care', $alert->target_departments);
        $this->assertContains('social_work', $alert->target_departments);
    }

    public function test_adl_threshold_breach_creates_audit_log_entry(): void
    {
        AdlThreshold::create([
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'ambulation',
            'threshold_level' => 'supervision',
            'set_by_user_id'  => $this->user->id,
            'set_at'          => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'      => 'ambulation',
                'independence_level' => 'total_dependent',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'participant.adl.threshold_breached',
            'resource_id' => $this->participant->id,
        ]);
    }

    // ─── Threshold update authorization ──────────────────────────────────────

    public function test_threshold_update_requires_primary_care_admin(): void
    {
        $adminUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($adminUser)
            ->putJson("/participants/{$this->participant->id}/adl/thresholds", [
                'thresholds' => [
                    'bathing' => 'extensive_assist',
                    'dressing' => 'limited_assist',
                ],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('emr_adl_thresholds', [
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'bathing',
            'threshold_level' => 'extensive_assist',
        ]);
    }

    public function test_standard_user_cannot_update_thresholds(): void
    {
        // $this->user is standard (not admin)
        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/adl/thresholds", [
                'thresholds' => ['bathing' => 'extensive_assist'],
            ])
            ->assertForbidden();
    }

    public function test_update_thresholds_upserts_correctly(): void
    {
        $admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'admin',
        ]);

        // First upsert
        $this->actingAs($admin)
            ->putJson("/participants/{$this->participant->id}/adl/thresholds", [
                'thresholds' => ['bathing' => 'limited_assist'],
            ])
            ->assertOk();

        // Second upsert — same category, different level
        $this->actingAs($admin)
            ->putJson("/participants/{$this->participant->id}/adl/thresholds", [
                'thresholds' => ['bathing' => 'extensive_assist'],
            ])
            ->assertOk();

        // Only one row for bathing (no duplicate)
        $this->assertDatabaseCount('emr_adl_thresholds', 1);
        $this->assertDatabaseHas('emr_adl_thresholds', [
            'participant_id'  => $this->participant->id,
            'adl_category'    => 'bathing',
            'threshold_level' => 'extensive_assist',
        ]);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_view_adl_from_different_tenant(): void
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
            ->getJson("/participants/{$otherParticipant->id}/adl")
            ->assertForbidden();
    }
}
