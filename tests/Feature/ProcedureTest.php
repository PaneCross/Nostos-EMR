<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Procedure;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcedureTest extends TestCase
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
            'mrn_prefix' => 'PRO',
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

    public function test_index_returns_procedures(): void
    {
        Procedure::factory()->count(3)->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/procedures");

        $response->assertOk()->assertJsonCount(3);
    }

    public function test_index_excludes_other_tenant_procedures(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'OPR']);
        $otherPt = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();

        Procedure::factory()->create(['participant_id' => $otherPt->id, 'tenant_id' => $other->id]);
        Procedure::factory()->create(['participant_id' => $this->participant->id, 'tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/procedures");

        $response->assertOk()->assertJsonCount(1);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_store_records_internal_procedure(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/procedures", [
                'procedure_name' => 'Cardiac Catheterization',
                'cpt_code'       => '93458',
                'performed_date' => '2025-08-20',
                'source'         => 'internal',
                'facility'       => 'PACE Day Center',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_procedures', [
            'participant_id' => $this->participant->id,
            'procedure_name' => 'Cardiac Catheterization',
            'cpt_code'       => '93458',
            'source'         => 'internal',
        ]);
    }

    public function test_store_records_external_report_procedure(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/procedures", [
                'procedure_name' => 'Total Hip Replacement',
                'performed_date' => '2024-03-10',
                'source'         => 'external_report',
                'facility'       => 'St. Mary Medical Center',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_procedures', [
            'procedure_name' => 'Total Hip Replacement',
            'source'         => 'external_report',
        ]);
    }

    public function test_store_rejects_invalid_source(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/procedures", [
                'procedure_name' => 'Test',
                'performed_date' => '2025-01-01',
                'source'         => 'not_valid',
            ]);

        $response->assertUnprocessable();
    }

    public function test_store_requires_procedure_name(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/procedures", [
                'performed_date' => '2025-01-01',
                'source'         => 'internal',
            ])
            ->assertUnprocessable();
    }

    // ─── Cross-tenant isolation ────────────────────────────────────────────────

    public function test_cannot_access_other_tenant_participant_procedures(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XPC']);
        $otherPt = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherPt->id}/procedures")
            ->assertForbidden();
    }

    // ─── Audit log ────────────────────────────────────────────────────────────

    public function test_store_writes_audit_log(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/procedures", [
                'procedure_name' => 'EKG',
                'performed_date' => '2025-06-01',
                'source'         => 'internal',
            ]);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'participant.procedure.recorded',
            'resource_type' => 'participant',
        ]);
    }
}
