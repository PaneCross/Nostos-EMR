<?php

// ─── DiagnosticReportMapper ───────────────────────────────────────────────────
// Maps a NostosEMR LabResult to a FHIR R4 DiagnosticReport resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/diagnosticreport.html
//
// Source: emr_lab_results (with emr_lab_result_components).
// W5-2: Updated to use structured lab result records instead of emr_integration_log.
//
// Components are mapped as contained FHIR Observation resources within the
// DiagnosticReport (using the #local reference pattern for simplicity).
// Each OBX-equivalent component becomes one Observation with a valueQuantity
// or valueString depending on whether the value is numeric.
//
// W4-9 — GAP-13: FHIR R4 DiagnosticReport resource (originally from integration log).
// W5-2 — Updated to pull from emr_lab_results + emr_lab_result_components.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\LabResult;
use App\Models\LabResultComponent;
use Carbon\Carbon;

class DiagnosticReportMapper
{
    /**
     * Map a LabResult (with loaded components) to a FHIR R4 DiagnosticReport resource.
     *
     * @param  LabResult  $lab  The structured lab result record (components must be loaded)
     */
    public static function toFhir(LabResult $lab): array
    {
        $components   = $lab->relationLoaded('components') ? $lab->components : collect();
        $contained    = [];
        $resultRefs   = [];

        // ── Map each component as a contained FHIR Observation ────────────────

        foreach ($components as $idx => $comp) {
            /** @var LabResultComponent $comp */
            $obsId = "obs-{$idx}";

            // Determine interpretation coding from abnormal_flag
            $interpretation = self::interpretationCoding($comp->abnormal_flag);

            // valueQuantity vs valueString: numeric values get Quantity; text gets String
            $valueKey   = 'valueString';
            $valueField = (string) $comp->value;

            if (is_numeric($comp->value)) {
                $valueKey   = 'valueQuantity';
                $valueField = [
                    'value' => (float) $comp->value,
                    'unit'  => $comp->unit,
                    'system'=> 'http://unitsofmeasure.org',
                    'code'  => $comp->unit,
                ];
            }

            $observation = [
                'resourceType' => 'Observation',
                'id'           => $obsId,
                'status'       => 'final',
                'code'         => [
                    'coding' => array_filter([
                        $comp->component_code ? [
                            'system'  => 'http://loinc.org',
                            'code'    => $comp->component_code,
                            'display' => $comp->component_name,
                        ] : null,
                    ]),
                    'text' => $comp->component_name,
                ],
                $valueKey => $valueField,
            ];

            if ($comp->reference_range) {
                $observation['referenceRange'] = [
                    ['text' => $comp->reference_range],
                ];
            }

            if ($interpretation) {
                $observation['interpretation'] = [$interpretation];
            }

            $contained[]  = $observation;
            $resultRefs[] = ['reference' => "#{$obsId}"];
        }

        // ── Build DiagnosticReport ────────────────────────────────────────────

        $report = [
            'resourceType' => 'DiagnosticReport',
            'id'           => (string) $lab->id,

            'status' => self::mapStatus($lab->overall_status),

            // ── Category ──────────────────────────────────────────────────────
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                            'code'    => 'LAB',
                            'display' => 'Laboratory',
                        ],
                    ],
                ],
            ],

            // ── Code (panel/test name) ─────────────────────────────────────────
            'code' => [
                'coding' => array_filter([
                    $lab->test_code ? [
                        'system'  => 'http://loinc.org',
                        'code'    => $lab->test_code,
                        'display' => $lab->test_name,
                    ] : null,
                    ['display' => $lab->test_name],
                ]),
                'text' => $lab->test_name,
            ],

            // ── Subject ───────────────────────────────────────────────────────
            'subject' => ['reference' => "Patient/{$lab->participant_id}"],

            // ── Effective date (specimen collection) ──────────────────────────
            'effectiveDateTime' => $lab->collected_at
                ? Carbon::parse($lab->collected_at)->toIso8601String()
                : null,

            // ── Issued date (when result was resulted / logged) ───────────────
            'issued' => ($lab->resulted_at ?? $lab->created_at)
                ? Carbon::parse($lab->resulted_at ?? $lab->created_at)->toIso8601String()
                : null,

            // ── Performer ─────────────────────────────────────────────────────
            'performer' => array_filter([
                $lab->performing_facility
                    ? ['display' => $lab->performing_facility]
                    : null,
                $lab->ordering_provider_name
                    ? ['display' => $lab->ordering_provider_name]
                    : null,
            ]),

            // ── Result references (contained Observations) ────────────────────
            'result' => $resultRefs,

            // ── Contained resources ───────────────────────────────────────────
            'contained' => $contained,

            // ── Conclusion ────────────────────────────────────────────────────
            'conclusion' => $lab->abnormal_flag ? 'Abnormal result — clinical review required.' : null,
        ];

        return $report;
    }

    /**
     * Map LabResult overall_status to FHIR DiagnosticReport status codes.
     * FHIR spec: registered|partial|preliminary|final|amended|corrected|appended|cancelled|entered-in-error
     */
    private static function mapStatus(string $status): string
    {
        return match ($status) {
            'final'       => 'final',
            'preliminary' => 'preliminary',
            'corrected'   => 'corrected',
            'cancelled'   => 'cancelled',
            default       => 'final',
        };
    }

    /**
     * Build a FHIR Observation interpretation coding from an abnormal_flag value.
     * FHIR uses v3 ObservationInterpretation codes.
     */
    private static function interpretationCoding(?string $flag): ?array
    {
        if ($flag === null || $flag === 'normal') {
            return null;
        }

        $map = [
            'high'         => ['code' => 'H',  'display' => 'High'],
            'low'          => ['code' => 'L',  'display' => 'Low'],
            'critical_high'=> ['code' => 'HH', 'display' => 'Critical High'],
            'critical_low' => ['code' => 'LL', 'display' => 'Critical Low'],
            'abnormal'     => ['code' => 'A',  'display' => 'Abnormal'],
        ];

        $entry = $map[$flag] ?? ['code' => 'A', 'display' => 'Abnormal'];

        return [
            'coding' => [
                [
                    'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation',
                    'code'    => $entry['code'],
                    'display' => $entry['display'],
                ],
            ],
            'text' => $entry['display'],
        ];
    }
}
