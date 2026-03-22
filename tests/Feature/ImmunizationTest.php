<?php

namespace Tests\Feature;

use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmunizationTest extends TestCase
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
            'mrn_prefix' => 'IMM',
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

    // ─── List ─────────────────────────────────────────────────────────────────

    public function test_index_returns_immunizations_for_participant(): void
    {
        Immunization::factory()->count(3)->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/immunizations");

        $response->assertOk()
            ->assertJsonCount(3);
    }

    public function test_index_excludes_other_tenant_immunizations(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $other = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)->forSite($otherSite->id)->create();

        Immunization::factory()->create([
            'participant_id' => $other->id,
            'tenant_id'      => $otherTenant->id,
        ]);
        Immunization::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/immunizations");

        $response->assertOk()->assertJsonCount(1);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_store_records_immunization(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'      => 'influenza',
                'vaccine_name'      => 'Fluzone High-Dose',
                'administered_date' => '2025-10-15',
                'lot_number'        => 'ABC123',
                'manufacturer'      => 'Sanofi',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_immunizations', [
            'participant_id' => $this->participant->id,
            'vaccine_type'   => 'influenza',
            'vaccine_name'   => 'Fluzone High-Dose',
            'lot_number'     => 'ABC123',
            'refused'        => false,
        ]);
    }

    public function test_store_records_refusal_with_reason(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'      => 'influenza',
                'vaccine_name'      => 'Influenza',
                'administered_date' => '2025-10-15',
                'refused'           => true,
                'refusal_reason'    => 'Patient declined citing prior reaction',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_immunizations', [
            'participant_id' => $this->participant->id,
            'refused'        => true,
            'refusal_reason' => 'Patient declined citing prior reaction',
        ]);
    }

    public function test_store_rejects_invalid_vaccine_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'      => 'not_a_vaccine',
                'vaccine_name'      => 'Test',
                'administered_date' => '2025-10-15',
            ]);

        $response->assertUnprocessable();
    }

    public function test_store_rejects_unauthenticated(): void
    {
        $this->postJson("/participants/{$this->participant->id}/immunizations", [
            'vaccine_type' => 'influenza', 'vaccine_name' => 'Flu', 'administered_date' => '2025-10-15',
        ])->assertUnauthorized();
    }

    // ─── Cross-tenant isolation ────────────────────────────────────────────────

    public function test_cannot_list_immunizations_for_other_tenant_participant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'XTN']);
        $other = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$other->id}/immunizations")
            ->assertForbidden();
    }

    // ─── Audit log ────────────────────────────────────────────────────────────

    public function test_store_writes_audit_log(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'      => 'covid_19',
                'vaccine_name'      => 'Moderna Updated',
                'administered_date' => '2025-09-01',
            ]);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'participant.immunization.recorded',
            'resource_type' => 'participant',
        ]);
    }
}
