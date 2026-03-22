<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\SocialDeterminant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialDeterminantsTest extends TestCase
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
            'mrn_prefix' => 'SDH',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'social_work',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function test_index_returns_screenings(): void
    {
        SocialDeterminant::factory()->count(2)->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/social-determinants");

        $response->assertOk()->assertJsonCount(2);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /** Valid baseline payload matching actual SocialDeterminant enum values. */
    private function validSdohPayload(array $overrides = []): array
    {
        return array_merge([
            'housing_stability'     => 'stable',
            'food_security'         => 'secure',
            'transportation_access' => 'adequate',
            'social_isolation_risk' => 'low',
            'caregiver_strain'      => 'none',
            'financial_strain'      => 'none',
        ], $overrides);
    }

    public function test_store_records_screening(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/social-determinants", $this->validSdohPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('emr_social_determinants', [
            'participant_id'    => $this->participant->id,
            'housing_stability' => 'stable',
            'food_security'     => 'secure',
        ]);
    }

    public function test_store_records_high_risk_screening(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/social-determinants", $this->validSdohPayload([
                'housing_stability'     => 'homeless',
                'food_security'         => 'insecure',
                'transportation_access' => 'none',
                'social_isolation_risk' => 'high',
                'caregiver_strain'      => 'severe',
                'financial_strain'      => 'severe',
            ]));

        $response->assertCreated();
        $this->assertDatabaseHas('emr_social_determinants', [
            'participant_id'        => $this->participant->id,
            'housing_stability'     => 'homeless',
            'social_isolation_risk' => 'high',
        ]);
    }

    public function test_store_rejects_invalid_housing_value(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/social-determinants", $this->validSdohPayload([
                'housing_stability' => 'invalid_value',
            ]));

        $response->assertUnprocessable();
    }

    public function test_store_requires_all_domain_fields(): void
    {
        // Controller marks all 6 domains as required
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/social-determinants", [
                'housing_stability' => 'at_risk',
                // Missing 5 required fields
            ])
            ->assertUnprocessable();
    }

    // ─── Cross-tenant isolation ────────────────────────────────────────────────

    public function test_cannot_access_other_tenant_participant_sdoh(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'OSD']);
        $otherPt = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherPt->id}/social-determinants")
            ->assertForbidden();
    }

    // ─── Audit log ────────────────────────────────────────────────────────────

    public function test_store_writes_audit_log(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/social-determinants", $this->validSdohPayload());

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'participant.sdoh.recorded',
            'resource_type' => 'participant',
        ]);
    }

    public function test_unauthenticated_request_rejected(): void
    {
        $this->getJson("/participants/{$this->participant->id}/social-determinants")
            ->assertUnauthorized();
    }
}
