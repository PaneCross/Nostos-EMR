<?php

// ─── LabResultParserTest ──────────────────────────────────────────────────────
// Unit tests for W5-2 HL7 lab result parsing logic within ProcessLabResultJob.
//
// Tests:
//   - normalizeAbnormalFlag maps HL7 codes (H, L, HH, LL, N, A) correctly
//   - normalizeAbnormalFlag passes through already-normalized enum values
//   - normalizeAbnormalFlag handles null + empty string gracefully
//   - Unknown flag values fall back to 'abnormal'
//   - DiagnosticReportMapper::toFhir maps all status values
//   - DiagnosticReportMapper builds contained Observations for components
//   - DiagnosticReportMapper handles lab result with no components
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Fhir\Mappers\DiagnosticReportMapper;
use App\Jobs\ProcessLabResultJob;
use App\Models\LabResult;
use App\Models\LabResultComponent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class LabResultParserTest extends TestCase
{
    use RefreshDatabase;

    // ── normalizeAbnormalFlag ─────────────────────────────────────────────────

    private function callNormalize(mixed $flag): ?string
    {
        // Access private method via reflection
        $job    = new ProcessLabResultJob(1, [], 1);
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('normalizeAbnormalFlag');
        $method->setAccessible(true);
        return $method->invoke($job, $flag);
    }

    public function test_normalize_hl7_high(): void
    {
        $this->assertEquals('high', $this->callNormalize('H'));
    }

    public function test_normalize_hl7_low(): void
    {
        $this->assertEquals('low', $this->callNormalize('L'));
    }

    public function test_normalize_hl7_critical_high(): void
    {
        $this->assertEquals('critical_high', $this->callNormalize('HH'));
    }

    public function test_normalize_hl7_critical_low(): void
    {
        $this->assertEquals('critical_low', $this->callNormalize('LL'));
    }

    public function test_normalize_hl7_normal(): void
    {
        $this->assertEquals('normal', $this->callNormalize('N'));
    }

    public function test_normalize_hl7_abnormal(): void
    {
        $this->assertEquals('abnormal', $this->callNormalize('A'));
    }

    public function test_normalize_already_normalized_enum(): void
    {
        $this->assertEquals('critical_low',  $this->callNormalize('critical_low'));
        $this->assertEquals('critical_high', $this->callNormalize('critical_high'));
        $this->assertEquals('normal',        $this->callNormalize('normal'));
        $this->assertEquals('high',          $this->callNormalize('high'));
        $this->assertEquals('low',           $this->callNormalize('low'));
    }

    public function test_normalize_null_returns_null(): void
    {
        $this->assertNull($this->callNormalize(null));
    }

    public function test_normalize_empty_string_returns_null(): void
    {
        $this->assertNull($this->callNormalize(''));
    }

    public function test_normalize_unknown_value_falls_back_to_abnormal(): void
    {
        $this->assertEquals('abnormal', $this->callNormalize('WEIRD_VALUE'));
        $this->assertEquals('abnormal', $this->callNormalize('X'));
    }

    // ── DiagnosticReportMapper ────────────────────────────────────────────────

    public function test_mapper_maps_final_status(): void
    {
        $lab = LabResult::factory()->make([
            'overall_status' => 'final',
            'test_name'      => 'Test',
        ]);
        // Manually set an id since factory()->make() doesn't persist
        $lab->id = 99;
        $lab->components = collect();

        $result = DiagnosticReportMapper::toFhir($lab);
        $this->assertEquals('final', $result['status']);
    }

    public function test_mapper_maps_preliminary_status(): void
    {
        $lab = LabResult::factory()->make([
            'overall_status' => 'preliminary',
            'test_name'      => 'TSH',
        ]);
        $lab->id         = 100;
        $lab->components = collect();

        $result = DiagnosticReportMapper::toFhir($lab);
        $this->assertEquals('preliminary', $result['status']);
    }

    public function test_mapper_includes_loinc_code_when_present(): void
    {
        $lab = LabResult::factory()->make([
            'test_name' => 'CBC',
            'test_code' => '58410-2',
        ]);
        $lab->id         = 101;
        $lab->components = collect();

        $result = DiagnosticReportMapper::toFhir($lab);
        $codes = $result['code']['coding'];

        $loincCode = collect($codes)->firstWhere('system', 'http://loinc.org');
        $this->assertNotNull($loincCode);
        $this->assertEquals('58410-2', $loincCode['code']);
    }

    public function test_mapper_builds_contained_observation_for_each_component(): void
    {
        $lab = LabResult::factory()->make([
            'test_name'      => 'BMP',
            'overall_status' => 'final',
        ]);
        $lab->id = 102;

        $comp1 = new LabResultComponent([
            'id'             => 1,
            'component_name' => 'Sodium',
            'component_code' => '2951-2',
            'value'          => '138',
            'unit'           => 'mEq/L',
            'reference_range'=> '136-145',
            'abnormal_flag'  => 'normal',
        ]);
        $comp2 = new LabResultComponent([
            'id'             => 2,
            'component_name' => 'Potassium',
            'value'          => '2.8',
            'unit'           => 'mEq/L',
            'abnormal_flag'  => 'critical_low',
        ]);
        $comp1->exists = false;
        $comp2->exists = false;

        $lab->setRelation('components', collect([$comp1, $comp2]));

        $result = DiagnosticReportMapper::toFhir($lab);

        $this->assertCount(2, $result['contained']);
        $this->assertCount(2, $result['result']);

        // Critical low should have LL interpretation code
        $potassiumObs = $result['contained'][1];
        $this->assertArrayHasKey('interpretation', $potassiumObs);
        $this->assertEquals('LL', $potassiumObs['interpretation'][0]['coding'][0]['code']);
    }

    public function test_mapper_handles_empty_components(): void
    {
        $lab = LabResult::factory()->make([
            'test_name'      => 'TSH',
            'overall_status' => 'final',
        ]);
        $lab->id         = 103;
        $lab->components = collect();

        $result = DiagnosticReportMapper::toFhir($lab);

        $this->assertEmpty($result['contained']);
        $this->assertEmpty($result['result']);
    }

    public function test_mapper_uses_valueQuantity_for_numeric_values(): void
    {
        $lab = LabResult::factory()->make(['test_name' => 'TSH']);
        $lab->id = 104;

        $comp = new LabResultComponent([
            'id'             => 3,
            'component_name' => 'TSH',
            'value'          => '2.5',
            'unit'           => 'mIU/L',
            'abnormal_flag'  => 'normal',
        ]);
        $comp->exists = false;
        $lab->setRelation('components', collect([$comp]));

        $result = DiagnosticReportMapper::toFhir($lab);

        $obs = $result['contained'][0];
        $this->assertArrayHasKey('valueQuantity', $obs);
        $this->assertArrayNotHasKey('valueString', $obs);
        $this->assertEquals(2.5, $obs['valueQuantity']['value']);
    }

    public function test_mapper_uses_valueString_for_text_values(): void
    {
        $lab = LabResult::factory()->make(['test_name' => 'Urinalysis']);
        $lab->id = 105;

        $comp = new LabResultComponent([
            'id'             => 4,
            'component_name' => 'Appearance',
            'value'          => 'Clear',
            'abnormal_flag'  => 'normal',
        ]);
        $comp->exists = false;
        $lab->setRelation('components', collect([$comp]));

        $result = DiagnosticReportMapper::toFhir($lab);

        $obs = $result['contained'][0];
        $this->assertArrayHasKey('valueString', $obs);
        $this->assertArrayNotHasKey('valueQuantity', $obs);
        $this->assertEquals('Clear', $obs['valueString']);
    }
}
