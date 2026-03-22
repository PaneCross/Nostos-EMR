<?php

namespace Tests\Unit;

use App\Models\DrugInteractionAlert;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DrugInteractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DrugInteractionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DrugInteractionService $service;
    private Tenant                 $tenant;
    private Participant            $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DrugInteractionService::class);

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'DIS',
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($site->id)
            ->create();
    }

    // ── Helper: seed an interaction pair ─────────────────────────────────────

    private function seedInteraction(string $drug1, string $drug2, string $severity = 'major'): void
    {
        // Normalize pair alphabetically (mirrors MedicationsReferenceSeeder logic)
        if ($drug1 > $drug2) {
            [$drug1, $drug2] = [$drug2, $drug1];
        }
        DB::table('emr_drug_interactions_reference')->insert([
            'drug_name_1' => $drug1,
            'drug_name_2' => $drug2,
            'severity'    => $severity,
            'description' => "Test interaction between {$drug1} and {$drug2}.",
        ]);
    }

    // ── checkInteractions: creates alert when interaction found ───────────────

    public function test_detects_interaction_between_two_active_meds(): void
    {
        $this->seedInteraction('Aspirin', 'Warfarin', 'major');

        $warfarin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Warfarin', 'status' => 'active']);

        $aspirin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin', 'status' => 'active']);

        $alerts = $this->service->checkInteractions($aspirin, $this->participant);

        $this->assertCount(1, $alerts);
        $this->assertEquals('major', $alerts->first()->severity);
        $this->assertDatabaseHas('emr_drug_interaction_alerts', [
            'participant_id'  => $this->participant->id,
            'is_acknowledged' => false,
        ]);
    }

    public function test_no_alert_when_no_interaction_in_reference(): void
    {
        // Metformin + Lisinopril: no interaction in reference
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Lisinopril', 'status' => 'active']);

        $metformin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Metformin', 'status' => 'active']);

        $alerts = $this->service->checkInteractions($metformin, $this->participant);

        $this->assertCount(0, $alerts);
        $this->assertDatabaseCount('emr_drug_interaction_alerts', 0);
    }

    public function test_no_alert_when_participant_has_no_other_meds(): void
    {
        $this->seedInteraction('Aspirin', 'Warfarin', 'major');

        $aspirin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin', 'status' => 'active']);

        // No other medications exist for this participant
        $alerts = $this->service->checkInteractions($aspirin, $this->participant);

        $this->assertCount(0, $alerts);
    }

    // ── Idempotency: no duplicate alerts ─────────────────────────────────────

    public function test_does_not_create_duplicate_alert_for_same_pair(): void
    {
        $this->seedInteraction('Aspirin', 'Warfarin', 'major');

        $warfarin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Warfarin', 'status' => 'active']);

        $aspirin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin', 'status' => 'active']);

        // First check
        $this->service->checkInteractions($aspirin, $this->participant);
        // Second check (same pair, unacknowledged alert already exists)
        $this->service->checkInteractions($aspirin, $this->participant);

        $this->assertDatabaseCount('emr_drug_interaction_alerts', 1);
    }

    public function test_creates_new_alert_after_first_is_acknowledged(): void
    {
        $this->seedInteraction('Aspirin', 'Warfarin', 'major');

        $warfarin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Warfarin', 'status' => 'active']);

        $aspirin = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin', 'status' => 'active']);

        // Create first alert and immediately acknowledge it
        $alerts = $this->service->checkInteractions($aspirin, $this->participant);
        $alerts->first()->update(['is_acknowledged' => true]);

        // Now add a third medication (Aspirin again — simulates re-prescribing)
        $aspirin2 = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin', 'status' => 'active']);

        $newAlerts = $this->service->checkInteractions($aspirin2, $this->participant);

        // Because the existing alert is acknowledged, a new one SHOULD be created
        $this->assertCount(1, $newAlerts);
        $this->assertDatabaseCount('emr_drug_interaction_alerts', 2);
    }

    // ── findInteraction: both orderings ──────────────────────────────────────

    public function test_find_interaction_matches_normalized_pair(): void
    {
        $this->seedInteraction('Aspirin', 'Warfarin', 'major');

        // Forward order
        $result1 = $this->service->findInteraction('Aspirin', 'Warfarin');
        $this->assertNotNull($result1);
        $this->assertEquals('major', $result1->severity);

        // Reverse order
        $result2 = $this->service->findInteraction('Warfarin', 'Aspirin');
        $this->assertNotNull($result2);
        $this->assertEquals('major', $result2->severity);
    }

    public function test_find_interaction_returns_null_when_not_found(): void
    {
        $result = $this->service->findInteraction('Lisinopril', 'Metformin');

        $this->assertNull($result);
    }

    // ── getUnacknowledgedAlerts ───────────────────────────────────────────────

    public function test_get_unacknowledged_alerts_excludes_acknowledged(): void
    {
        $med1 = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Warfarin']);
        $med2 = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Aspirin']);

        // Acknowledged alert
        DrugInteractionAlert::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->acknowledged()
            ->create([
                'medication_id_1' => $med1->id,
                'medication_id_2' => $med2->id,
            ]);

        // Unacknowledged alert
        DrugInteractionAlert::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id_1' => $med1->id,
                'medication_id_2' => $med2->id,
            ]);

        $alerts = $this->service->getUnacknowledgedAlerts($this->participant);

        $this->assertCount(1, $alerts);
        $this->assertFalse((bool) $alerts->first()->is_acknowledged);
    }
}
