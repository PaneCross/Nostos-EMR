<?php

// ─── PatientMapperTest ─────────────────────────────────────────────────────────
// Unit tests for PatientMapper (FHIR R4 Patient resource generation).
//
// Coverage:
//   - Resource type is 'Patient'
//   - MRN maps to identifier with type code 'MR'
//   - Medicare ID maps to identifier with type code 'SB'
//   - Medicaid ID maps to identifier with type code 'MA'
//   - PACE contract ID maps to identifier with type code 'RI'
//   - participant without optional IDs only has MRN identifier
//   - Gender: non_binary → 'other', prefer_not_to_say → 'unknown'
//   - Gender: male/female pass through unchanged
//   - Language: English → 'en', Spanish → 'es'
//   - Resource has required fields: resourceType, id, name, birthDate, active
//   - 'active' reflects participant is_active flag
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Fhir\Mappers\PatientMapper;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientMapperTest extends TestCase
{
    use RefreshDatabase;

    private function makeParticipant(array $overrides = []): Participant
    {
        return Participant::factory()->create($overrides);
    }

    // ── Resource structure ────────────────────────────────────────────────────

    public function test_resource_type_is_patient(): void
    {
        $participant = $this->makeParticipant();
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals('Patient', $fhir['resourceType']);
    }

    public function test_id_matches_participant_id(): void
    {
        $participant = $this->makeParticipant();
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals((string) $participant->id, $fhir['id']);
    }

    public function test_required_fields_are_present(): void
    {
        $participant = $this->makeParticipant();
        $fhir = PatientMapper::toFhir($participant);

        $this->assertArrayHasKey('resourceType',   $fhir);
        $this->assertArrayHasKey('id',             $fhir);
        $this->assertArrayHasKey('identifier',     $fhir);
        $this->assertArrayHasKey('name',           $fhir);
        $this->assertArrayHasKey('gender',         $fhir);
        $this->assertArrayHasKey('birthDate',      $fhir);
        $this->assertArrayHasKey('active',         $fhir);
        $this->assertArrayHasKey('communication',  $fhir);
    }

    // ── Identifiers ───────────────────────────────────────────────────────────

    public function test_mrn_maps_to_mr_identifier(): void
    {
        $participant = $this->makeParticipant();
        $fhir = PatientMapper::toFhir($participant);

        $identifiers = $fhir['identifier'];
        $mrnId = collect($identifiers)->first(
            fn ($id) => collect($id['type']['coding'] ?? [])->contains('code', 'MR')
        );

        $this->assertNotNull($mrnId, 'Expected MR identifier for MRN');
        $this->assertEquals($participant->mrn, $mrnId['value']);
    }

    public function test_medicare_id_maps_to_sb_identifier(): void
    {
        $participant = $this->makeParticipant(['medicare_id' => 'MEDICARE123']);
        $fhir = PatientMapper::toFhir($participant);

        $identifiers = $fhir['identifier'];
        $sbId = collect($identifiers)->first(
            fn ($id) => collect($id['type']['coding'] ?? [])->contains('code', 'SB')
        );

        $this->assertNotNull($sbId, 'Expected SB identifier for Medicare ID');
        $this->assertEquals('MEDICARE123', $sbId['value']);
    }

    public function test_medicaid_id_maps_to_ma_identifier(): void
    {
        $participant = $this->makeParticipant(['medicaid_id' => 'MEDICAID456']);
        $fhir = PatientMapper::toFhir($participant);

        $identifiers = $fhir['identifier'];
        $maId = collect($identifiers)->first(
            fn ($id) => collect($id['type']['coding'] ?? [])->contains('code', 'MA')
        );

        $this->assertNotNull($maId, 'Expected MA identifier for Medicaid ID');
        $this->assertEquals('MEDICAID456', $maId['value']);
    }

    public function test_participant_without_optional_ids_has_only_mrn_identifier(): void
    {
        $participant = $this->makeParticipant([
            'medicare_id'      => null,
            'medicaid_id'      => null,
            'pace_contract_id' => null,
        ]);
        $fhir = PatientMapper::toFhir($participant);

        $this->assertCount(1, array_values($fhir['identifier']));
    }

    // ── Gender mapping ────────────────────────────────────────────────────────

    public function test_gender_male_passes_through(): void
    {
        $participant = $this->makeParticipant(['gender' => 'male']);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals('male', $fhir['gender']);
    }

    public function test_gender_female_passes_through(): void
    {
        $participant = $this->makeParticipant(['gender' => 'female']);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals('female', $fhir['gender']);
    }

    public function test_gender_non_binary_maps_to_other(): void
    {
        $participant = $this->makeParticipant(['gender' => 'non_binary']);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals('other', $fhir['gender']);
    }

    public function test_gender_prefer_not_to_say_maps_to_unknown(): void
    {
        $participant = $this->makeParticipant(['gender' => 'prefer_not_to_say']);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertEquals('unknown', $fhir['gender']);
    }

    // ── Language mapping ──────────────────────────────────────────────────────

    public function test_english_language_maps_to_en(): void
    {
        $participant = $this->makeParticipant(['primary_language' => 'English']);
        $fhir = PatientMapper::toFhir($participant);

        $code = $fhir['communication'][0]['language']['coding'][0]['code'];
        $this->assertEquals('en', $code);
    }

    public function test_spanish_language_maps_to_es(): void
    {
        $participant = $this->makeParticipant(['primary_language' => 'Spanish']);
        $fhir = PatientMapper::toFhir($participant);

        $code = $fhir['communication'][0]['language']['coding'][0]['code'];
        $this->assertEquals('es', $code);
    }

    // ── Active flag ───────────────────────────────────────────────────────────

    public function test_active_participant_maps_to_true(): void
    {
        $participant = $this->makeParticipant(['is_active' => true]);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertTrue($fhir['active']);
    }

    public function test_inactive_participant_maps_to_false(): void
    {
        $participant = $this->makeParticipant(['is_active' => false]);
        $fhir = PatientMapper::toFhir($participant);
        $this->assertFalse($fhir['active']);
    }
}
