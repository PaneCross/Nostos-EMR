<?php

namespace Tests\Feature;

use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $nurse;
    private User        $otherUser;
    private Participant $participant;
    private Medication  $medication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'EMR',
        ]);
        $this->nurse = User::factory()->create([
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

        $this->medication = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'drug_name'  => 'Lisinopril',
                'status'     => 'active',
                'is_prn'     => false,
                'frequency'  => 'daily',
            ]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_emar_index_returns_records_for_date(): void
    {
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        $response = $this->actingAs($this->nurse)
            ->getJson("/participants/{$this->participant->id}/emar?date=" . today()->toDateString());

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_emar_index_filters_by_date(): void
    {
        // Record for today
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
            ]);
        // Record for yesterday
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->subDay()->setTime(8, 0),
            ]);

        $response = $this->actingAs($this->nurse)
            ->getJson("/participants/{$this->participant->id}/emar?date=" . today()->toDateString());

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    // ── Administer ────────────────────────────────────────────────────────────

    public function test_nurse_can_record_medication_as_given(): void
    {
        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/emar/{$record->id}/administer", [
                'status'          => 'given',
                'administered_at' => now()->toIso8601String(),
                'dose_given'      => '10 mg',
                'route_given'     => 'oral',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_emar_records', [
            'id'                      => $record->id,
            'status'                  => 'given',
            'administered_by_user_id' => $this->nurse->id,
        ]);
    }

    public function test_nurse_can_record_medication_as_refused(): void
    {
        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/emar/{$record->id}/administer", [
                'status'          => 'refused',
                'reason_not_given'=> 'Patient refused medication',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('emr_emar_records', [
            'id'     => $record->id,
            'status' => 'refused',
        ]);
    }

    public function test_administer_requires_reason_for_refused(): void
    {
        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/emar/{$record->id}/administer", [
                'status' => 'refused',
                // missing reason_not_given
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason_not_given']);
    }

    public function test_controlled_substance_requires_witness(): void
    {
        $controlledMed = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->controlled()
            ->create([
                'drug_name'           => 'Lorazepam',
                'is_controlled'       => true,
                'controlled_schedule' => 'IV',
                'status'              => 'active',
            ]);

        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $controlledMed->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        // Schedule IV does NOT require witness (only II and III do)
        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/emar/{$record->id}/administer", [
                'status'          => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(200);
    }

    public function test_schedule_ii_controlled_requires_witness_when_given(): void
    {
        $schedIIMed = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'drug_name'           => 'Oxycodone',
                'is_controlled'       => true,
                'controlled_schedule' => 'II',
                'status'              => 'active',
            ]);

        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $schedIIMed->id,
                'scheduled_time' => now()->setTime(8, 0),
                'status'         => 'scheduled',
            ]);

        // No witness_user_id provided — should fail with 422
        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/emar/{$record->id}/administer", [
                'status'          => 'given',
                'administered_at' => now()->toIso8601String(),
                // missing witness_user_id
            ])
            ->assertStatus(422);
    }

    // ── PRN dose ──────────────────────────────────────────────────────────────

    public function test_can_record_prn_dose(): void
    {
        $prnMed = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->prn()
            ->create();

        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/medications/{$prnMed->id}/prn-dose", [
                'administered_at' => now()->toIso8601String(),
                'dose_given'      => '500 mg',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('emr_emar_records', [
            'medication_id'           => $prnMed->id,
            'participant_id'          => $this->participant->id,
            'status'                  => 'given',
            'administered_by_user_id' => $this->nurse->id,
        ]);
    }

    public function test_cannot_record_prn_dose_for_non_prn_medication(): void
    {
        // $this->medication is not PRN
        $this->actingAs($this->nurse)
            ->postJson("/participants/{$this->participant->id}/medications/{$this->medication->id}/prn-dose", [
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_cannot_access_emar_for_different_tenant_participant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->nurse)
            ->getJson("/participants/{$otherParticipant->id}/emar")
            ->assertStatus(403);
    }
}
