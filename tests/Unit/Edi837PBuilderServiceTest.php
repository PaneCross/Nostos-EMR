<?php

// ─── Edi837PBuilderServiceTest ────────────────────────────────────────────────
// Unit tests for Edi837PBuilderService.
//
// Coverage:
//   - test_generate_encounter_batch_creates_edi_batch_record
//   - test_generate_encounter_batch_marks_encounters_submitted
//   - test_generate_encounter_batch_builds_valid_x12_content
//   - test_generate_encounter_batch_throws_on_non_submittable_encounters
//   - test_batch_result_has_correct_record_count
//   - test_parse_acknowledgement_sets_acknowledged_status
//   - test_parse_acknowledgement_rejected_updates_to_rejected
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\EdiBatch;
use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\User;
use App\Services\Edi837PBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Edi837PBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Edi837PBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(Edi837PBuilderService::class);
    }

    private function makeSubmittableEncounter(User $user): EncounterLog
    {
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        return EncounterLog::factory()->create([
            'tenant_id'            => $user->tenant_id,
            'participant_id'       => $participant->id,
            'billing_provider_npi' => '1234567890',
            'procedure_code'       => '99213',
            'diagnosis_codes'      => ['E119', 'I4891'],
            'charge_amount'        => 150.00,
            'units'                => 1.00,
            'place_of_service_code' => '11',
            'submission_status'    => 'pending',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_generate_encounter_batch_creates_edi_batch_record(): void
    {
        $user = User::factory()->create(['department' => 'finance']);
        $enc  = $this->makeSubmittableEncounter($user);

        $batch = $this->service->generateEncounterBatch(
            $user->tenant_id,
            [$enc->id],
            $user->id
        );

        $this->assertInstanceOf(EdiBatch::class, $batch);
        $this->assertDatabaseHas('emr_edi_batches', ['id' => $batch->id]);
    }

    public function test_generate_encounter_batch_marks_encounters_submitted(): void
    {
        $user = User::factory()->create(['department' => 'finance']);
        $enc  = $this->makeSubmittableEncounter($user);

        $this->service->generateEncounterBatch($user->tenant_id, [$enc->id], $user->id);

        $this->assertEquals('submitted', $enc->fresh()->submission_status);
    }

    public function test_generate_encounter_batch_builds_valid_x12_content(): void
    {
        $user = User::factory()->create(['department' => 'finance']);
        $enc  = $this->makeSubmittableEncounter($user);

        $batch = $this->service->generateEncounterBatch($user->tenant_id, [$enc->id], $user->id);

        // X12 files must start with ISA segment
        $this->assertStringStartsWith('ISA*', $batch->file_content);
        // Must contain GS (Group Header)
        $this->assertStringContainsString('GS*', $batch->file_content);
        // Must contain NM1 (Name) segment
        $this->assertStringContainsString('NM1*', $batch->file_content);
    }

    public function test_generate_encounter_batch_throws_on_non_submittable_encounters(): void
    {
        $user = User::factory()->create(['department' => 'finance']);
        $part = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        // Encounter missing diagnosis_codes — not submittable
        $enc = EncounterLog::factory()->create([
            'tenant_id'       => $user->tenant_id,
            'participant_id'  => $part->id,
            'diagnosis_codes' => [],
            'procedure_code'  => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateEncounterBatch($user->tenant_id, [$enc->id], $user->id);
    }

    public function test_batch_result_has_correct_record_count(): void
    {
        $user = User::factory()->create(['department' => 'finance']);
        $enc1 = $this->makeSubmittableEncounter($user);
        $enc2 = $this->makeSubmittableEncounter($user);
        $enc3 = $this->makeSubmittableEncounter($user);

        $batch = $this->service->generateEncounterBatch(
            $user->tenant_id,
            [$enc1->id, $enc2->id, $enc3->id],
            $user->id
        );

        $this->assertEquals(3, $batch->record_count);
    }

    public function test_parse_acknowledgement_sets_acknowledged_status(): void
    {
        $user  = User::factory()->create(['department' => 'finance']);
        $batch = EdiBatch::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'submitted',
        ]);

        // 277CA with STC A1:20 = accepted (use ~ as X12 segment terminator)
        $ack277 = "ISA*00*          *00*          *ZZ*CMSEDS*ZZ*TEST*250101*1200*^*00501*000000001*0*P*:~"
                . "GS*FA*CMSEDS*TEST*20250101*1200*1*X*005010X231A1~"
                . "ST*277*0001*005010X231A1~"
                . "BHT*0085*08*{$batch->id}*20250101*1200~"
                . "STC*A1:20*20250101**350.00~"
                . "SE*5*0001~GE*1*1~IEA*1*000000001~";

        $this->service->parseAcknowledgement($ack277, $batch);

        $this->assertEquals('acknowledged', $batch->fresh()->status);
    }

    public function test_parse_acknowledgement_rejected_updates_to_rejected(): void
    {
        $user  = User::factory()->create(['department' => 'finance']);
        $batch = EdiBatch::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'submitted',
        ]);

        // 277CA with STC R3:99 = rejected (R prefix = rejected per CMS 277CA spec)
        $ack277 = "ISA*00*          *00*          *ZZ*CMSEDS*ZZ*TEST*250101*1200*^*00501*000000001*0*P*:~"
                . "GS*FA*CMSEDS*TEST*20250101*1200*1*X*005010X231A1~"
                . "ST*277*0001*005010X231A1~"
                . "BHT*0085*08*{$batch->id}*20250101*1200~"
                . "STC*R3:99*20250101~"
                . "SE*5*0001~GE*1*1~IEA*1*000000001~";

        $this->service->parseAcknowledgement($ack277, $batch);

        $this->assertEquals('rejected', $batch->fresh()->status);
    }
}
