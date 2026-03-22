<?php

// ─── ObservationMapperTest ────────────────────────────────────────────────────
// Unit tests for ObservationMapper (FHIR R4 Observation resource generation).
//
// Coverage:
//   - BP systolic gets LOINC code 8480-6
//   - BP diastolic gets LOINC code 8462-4
//   - Weight gets LOINC 29463-7 and is converted from lbs to kg
//   - Pulse gets LOINC 8867-4
//   - O2 saturation gets LOINC 59408-5
//   - Temperature gets LOINC 8310-5 and is converted from °F to °C
//   - Null measurements are omitted from the collection
//   - Collection count matches non-null measurements
//   - Each Observation has resourceType 'Observation'
//   - id format is "vital-{id}-{loincCode}"
//   - category is 'vital-signs'
//   - subject references correct participant
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Fhir\Mappers\ObservationMapper;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationMapperTest extends TestCase
{
    use RefreshDatabase;

    private function makeVital(array $overrides = []): Vital
    {
        return Vital::factory()->create($overrides);
    }

    // ── Collection size ───────────────────────────────────────────────────────

    public function test_null_measurements_are_omitted(): void
    {
        // Only bp_systolic set; all others null
        $vital = $this->makeVital([
            'bp_systolic'  => 120,
            'bp_diastolic' => null,
            'weight_lbs'   => null,
            'pulse'        => null,
            'o2_saturation'=> null,
            'temperature_f'=> null,
        ]);

        $collection = ObservationMapper::toFhirCollection($vital);
        $this->assertCount(1, $collection);
    }

    public function test_all_measurements_produce_six_observations(): void
    {
        $vital = $this->makeVital([
            'bp_systolic'  => 120,
            'bp_diastolic' => 80,
            'weight_lbs'   => 150.0,
            'pulse'        => 72,
            'o2_saturation'=> 98,
            'temperature_f'=> 98.6,
        ]);

        $collection = ObservationMapper::toFhirCollection($vital);
        $this->assertCount(6, $collection);
    }

    // ── LOINC codes ───────────────────────────────────────────────────────────

    public function test_bp_systolic_gets_loinc_8480_6(): void
    {
        $vital = $this->makeVital(['bp_systolic' => 130]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '8480-6'));
        $this->assertNotNull($obs);
        $this->assertEquals('8480-6', $obs['code']['coding'][0]['code']);
    }

    public function test_bp_diastolic_gets_loinc_8462_4(): void
    {
        $vital = $this->makeVital(['bp_diastolic' => 85]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '8462-4'));
        $this->assertNotNull($obs);
        $this->assertEquals('8462-4', $obs['code']['coding'][0]['code']);
    }

    public function test_pulse_gets_loinc_8867_4(): void
    {
        $vital = $this->makeVital(['pulse' => 75]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '8867-4'));
        $this->assertNotNull($obs);
        $this->assertEquals('8867-4', $obs['code']['coding'][0]['code']);
    }

    public function test_o2_saturation_gets_loinc_59408_5(): void
    {
        $vital = $this->makeVital(['o2_saturation' => 97]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '59408-5'));
        $this->assertNotNull($obs);
        $this->assertEquals('59408-5', $obs['code']['coding'][0]['code']);
    }

    // ── Unit conversions ──────────────────────────────────────────────────────

    public function test_weight_is_converted_from_lbs_to_kg(): void
    {
        $vital = $this->makeVital(['weight_lbs' => 150.0]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '29463-7'));
        $this->assertNotNull($obs);

        $expectedKg = round(150.0 * 0.453592, 2);
        $this->assertEquals($expectedKg, $obs['valueQuantity']['value']);
        $this->assertEquals('kg', $obs['valueQuantity']['unit']);
    }

    public function test_temperature_is_converted_from_f_to_celsius(): void
    {
        $vital = $this->makeVital(['temperature_f' => 98.6]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = collect($collection)->first(fn ($o) => str_contains($o['id'], '8310-5'));
        $this->assertNotNull($obs);

        $expectedCelsius = round((98.6 - 32) * 5 / 9, 2);
        $this->assertEqualsWithDelta($expectedCelsius, $obs['valueQuantity']['value'], 0.01);
        $this->assertEquals('Cel', $obs['valueQuantity']['unit']);
    }

    // ── Resource structure ────────────────────────────────────────────────────

    public function test_each_observation_has_correct_resource_type(): void
    {
        $vital = $this->makeVital(['bp_systolic' => 120, 'pulse' => 70]);
        $collection = ObservationMapper::toFhirCollection($vital);

        foreach ($collection as $obs) {
            $this->assertEquals('Observation', $obs['resourceType']);
        }
    }

    public function test_id_format_is_vital_id_loinc_code(): void
    {
        $vital = $this->makeVital(['bp_systolic' => 120]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $obs = $collection[0];
        $this->assertStringStartsWith("vital-{$vital->id}-", $obs['id']);
    }

    public function test_category_is_vital_signs(): void
    {
        $vital = $this->makeVital(['bp_systolic' => 120]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $category = $collection[0]['category'][0]['coding'][0]['code'];
        $this->assertEquals('vital-signs', $category);
    }

    public function test_subject_references_correct_participant(): void
    {
        $vital = $this->makeVital(['bp_systolic' => 120]);
        $collection = ObservationMapper::toFhirCollection($vital);

        $subject = $collection[0]['subject']['reference'];
        $this->assertEquals("Patient/{$vital->participant_id}", $subject);
    }
}
