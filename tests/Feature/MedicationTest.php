<?php

namespace Tests\Feature;

use App\Models\DrugInteractionAlert;
use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $prescriber;
    private User        $otherUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'MED',
        ]);
        $this->prescriber = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->otherUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'activities',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_prescriber_can_add_medication(): void
    {
        $response = $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name'  => 'Lisinopril',
                'dose'       => 10,
                'dose_unit'  => 'mg',
                'route'      => 'oral',
                'frequency'  => 'daily',
                'start_date' => now()->toDateString(),
                'is_prn'     => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_medications', [
            'participant_id' => $this->participant->id,
            'drug_name'      => 'Lisinopril',
            'status'         => 'active',
            'tenant_id'      => $this->tenant->id,
        ]);
    }

    public function test_non_prescriber_cannot_add_medication(): void
    {
        $this->actingAs($this->otherUser)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name'  => 'Aspirin',
                'start_date' => now()->toDateString(),
                'is_prn'     => false,
            ])
            ->assertStatus(403);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drug_name', 'start_date']);
    }

    public function test_store_validates_dose_unit_enum(): void
    {
        $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name'  => 'Aspirin',
                'dose_unit'  => 'tablespoons',  // invalid
                'start_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dose_unit']);
    }

    // ── Drug interactions ─────────────────────────────────────────────────────

    public function test_adding_interacting_drug_creates_interaction_alert(): void
    {
        // Seed a known interaction into the reference table
        \DB::table('emr_drug_interactions_reference')->insert([
            'drug_name_1' => 'Aspirin',
            'drug_name_2' => 'Warfarin',
            'severity'    => 'major',
            'description' => 'Increases bleeding risk.',
        ]);

        // Add first medication (Warfarin)
        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create([
            'drug_name' => 'Warfarin',
            'status'    => 'active',
        ]);

        // Add second medication (Aspirin) — should trigger interaction check
        $response = $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name'  => 'Aspirin',
                'dose'       => 81,
                'dose_unit'  => 'mg',
                'route'      => 'oral',
                'frequency'  => 'daily',
                'start_date' => now()->toDateString(),
                'is_prn'     => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_drug_interaction_alerts', [
            'participant_id'  => $this->participant->id,
            'drug_name_1'     => 'Aspirin',
            'drug_name_2'     => 'Warfarin',
            'severity'        => 'major',
            'is_acknowledged' => false,
        ]);
        $this->assertCount(1, $response->json('new_alerts'));
    }

    public function test_interaction_alert_not_duplicated_on_second_store(): void
    {
        \DB::table('emr_drug_interactions_reference')->insert([
            'drug_name_1' => 'Aspirin',
            'drug_name_2' => 'Warfarin',
            'severity'    => 'major',
            'description' => 'Increases bleeding risk.',
        ]);

        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create([
            'drug_name' => 'Warfarin',
            'status'    => 'active',
        ]);

        // Add Aspirin once
        $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name' => 'Aspirin', 'dose' => 81, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily', 'start_date' => now()->toDateString(), 'is_prn' => false,
            ]);

        // The interaction alert is now unacknowledged — adding Aspirin again (different record)
        // should NOT create a duplicate unacknowledged alert
        $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications", [
                'drug_name' => 'Aspirin', 'dose' => 81, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily', 'start_date' => now()->toDateString(), 'is_prn' => false,
            ]);

        $this->assertEquals(1, DrugInteractionAlert::where('participant_id', $this->participant->id)
            ->where('is_acknowledged', false)
            ->count());
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_medications_for_participant(): void
    {
        Medication::factory()->count(3)->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create([
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->prescriber)
            ->getJson("/participants/{$this->participant->id}/medications");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('medications'));
    }

    public function test_index_scoped_to_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)->forSite($otherSite->id)->create();

        // Medication on wrong tenant
        Medication::factory()->forParticipant($otherParticipant->id)->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);

        $response = $this->actingAs($this->prescriber)
            ->getJson("/participants/{$this->participant->id}/medications");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('medications'));
    }

    // ── Discontinue ───────────────────────────────────────────────────────────

    public function test_prescriber_can_discontinue_medication(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)->create(['status' => 'active']);

        $this->actingAs($this->prescriber)
            ->putJson("/participants/{$this->participant->id}/medications/{$med->id}/discontinue", [
                'reason' => 'No longer needed',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_medications', [
            'id'     => $med->id,
            'status' => 'discontinued',
        ]);
    }

    public function test_cannot_discontinue_already_discontinued_medication(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)->create(['status' => 'discontinued', 'discontinued_reason' => 'Already done']);

        $this->actingAs($this->prescriber)
            ->putJson("/participants/{$this->participant->id}/medications/{$med->id}/discontinue")
            ->assertStatus(409);
    }

    public function test_non_prescriber_cannot_discontinue_medication(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)->create(['status' => 'active']);

        $this->actingAs($this->otherUser)
            ->putJson("/participants/{$this->participant->id}/medications/{$med->id}/discontinue")
            ->assertStatus(403);
    }

    // ── Acknowledge interaction ───────────────────────────────────────────────

    public function test_clinician_can_acknowledge_interaction_alert(): void
    {
        $med1 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Warfarin']);
        $med2 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Aspirin']);
        $alert = DrugInteractionAlert::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'medication_id_1' => $med1->id,
            'medication_id_2' => $med2->id,
            'is_acknowledged' => false,
        ]);

        $this->actingAs($this->prescriber)
            ->postJson("/participants/{$this->participant->id}/medications/interactions/{$alert->id}/acknowledge", [
                'acknowledgement_note' => 'Benefit outweighs risk',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_drug_interaction_alerts', [
            'id'              => $alert->id,
            'is_acknowledged' => true,
        ]);
    }

    public function test_interactions_endpoint_returns_active_and_reviewed_keys(): void
    {
        $med1 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Warfarin']);
        $med2 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Aspirin']);

        // Unacknowledged alert
        DrugInteractionAlert::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'medication_id_1' => $med1->id,
            'medication_id_2' => $med2->id,
            'is_acknowledged' => false,
        ]);

        $this->actingAs($this->prescriber)
            ->getJson("/participants/{$this->participant->id}/medications/interactions")
            ->assertOk()
            ->assertJsonStructure(['active', 'reviewed']);
    }

    public function test_acknowledged_alerts_appear_in_reviewed_section(): void
    {
        $med1 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Warfarin']);
        $med2 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Aspirin']);
        $alert = DrugInteractionAlert::factory()->create([
            'participant_id'          => $this->participant->id,
            'tenant_id'               => $this->tenant->id,
            'medication_id_1'         => $med1->id,
            'medication_id_2'         => $med2->id,
            'is_acknowledged'         => true,
            'acknowledged_by_user_id' => $this->prescriber->id,
            'acknowledged_at'         => now()->subDay(),
            'acknowledgement_note'    => 'Benefit outweighs risk — low dose aspirin only.',
        ]);

        $response = $this->actingAs($this->prescriber)
            ->getJson("/participants/{$this->participant->id}/medications/interactions")
            ->assertOk();

        $this->assertCount(0, $response->json('active'));
        $this->assertCount(1, $response->json('reviewed'));
        $this->assertEquals($alert->id, $response->json('reviewed.0.id'));
        $this->assertNotNull($response->json('reviewed.0.acknowledged_by_name'));
    }

    public function test_acknowledged_alerts_older_than_90_days_excluded_from_reviewed(): void
    {
        $med1 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Warfarin']);
        $med2 = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)->create(['drug_name' => 'Aspirin']);
        DrugInteractionAlert::factory()->create([
            'participant_id'          => $this->participant->id,
            'tenant_id'               => $this->tenant->id,
            'medication_id_1'         => $med1->id,
            'medication_id_2'         => $med2->id,
            'is_acknowledged'         => true,
            'acknowledged_by_user_id' => $this->prescriber->id,
            'acknowledged_at'         => now()->subDays(91),
            'acknowledgement_note'    => 'Old acknowledgement.',
        ]);

        $response = $this->actingAs($this->prescriber)
            ->getJson("/participants/{$this->participant->id}/medications/interactions")
            ->assertOk();

        $this->assertCount(0, $response->json('reviewed'));
    }
}
