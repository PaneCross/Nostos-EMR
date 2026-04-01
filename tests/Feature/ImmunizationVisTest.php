<?php

// ─── ImmunizationVisTest (W4-4 QW-11) ────────────────────────────────────────
// Tests VIS (Vaccine Information Statement) fields on immunization records.
// Required by 42 USC 300aa-26 and CMS PACE guidelines.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmunizationVisTest extends TestCase
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
            'mrn_prefix' => 'VIS',
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

    public function test_vis_given_stored_with_immunization(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'        => 'influenza',
                'vaccine_name'        => 'Fluzone HD',
                'administered_date'   => today()->toDateString(),
                'vis_given'           => true,
                'vis_publication_date'=> '2024-08-01',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_immunizations', [
            'participant_id'     => $this->participant->id,
            'vis_given'          => true,
        ]);
    }

    public function test_vis_not_given_defaults_false(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'      => 'influenza',
                'vaccine_name'      => 'Fluzone',
                'administered_date' => today()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_immunizations', [
            'participant_id' => $this->participant->id,
            'vis_given'      => false,
        ]);
    }

    public function test_vis_publication_date_returned_in_index(): void
    {
        Immunization::factory()->create([
            'participant_id'      => $this->participant->id,
            'tenant_id'           => $this->tenant->id,
            'vis_given'           => true,
            'vis_publication_date'=> '2024-08-01',
        ]);

        $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/immunizations")
            ->assertOk()
            ->assertJsonPath('0.vis_given', true)
            ->assertJsonPath('0.vis_publication_date', '2024-08-01');
    }

    public function test_vis_publication_date_future_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/immunizations", [
                'vaccine_type'        => 'influenza',
                'vaccine_name'        => 'Fluzone',
                'administered_date'   => today()->toDateString(),
                'vis_given'           => true,
                'vis_publication_date'=> today()->addYear()->toDateString(),
            ])
            ->assertUnprocessable();
    }
}
