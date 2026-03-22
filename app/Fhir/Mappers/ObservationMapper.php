<?php

// ─── ObservationMapper ────────────────────────────────────────────────────────
// Maps a NostosEMR Vital record to an array of FHIR R4 Observation resources.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/observation.html
//
// LOINC codes used:
//   8480-6  Blood Pressure Systolic
//   8462-4  Blood Pressure Diastolic
//   29463-7 Body Weight (lbs converted to kg for FHIR)
//   8867-4  Heart Rate / Pulse
//   59408-5 O2 Saturation (pulse oximetry)
//   8310-5  Body Temperature (°F converted to °C for FHIR)
//
// One Vital row → multiple Observation resources (one per non-null measurement).
// Returned as an array; the controller wraps these in a FHIR Bundle.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Vital;

class ObservationMapper
{
    // LOINC code → [display, UCUM unit]
    private const LOINC_MAP = [
        '8480-6'  => ['Blood Pressure Systolic',  'mm[Hg]'],
        '8462-4'  => ['Blood Pressure Diastolic', 'mm[Hg]'],
        '29463-7' => ['Body Weight',              'kg'],
        '8867-4'  => ['Heart Rate',               '/min'],
        '59408-5' => ['Oxygen Saturation',        '%'],
        '8310-5'  => ['Body Temperature',         'Cel'],
    ];

    /**
     * Map a Vital model to an array of FHIR R4 Observation resources.
     * Each non-null measurement becomes a separate Observation.
     *
     * @return array<int, array> Array of FHIR Observation resource arrays
     */
    public static function toFhirCollection(Vital $vital): array
    {
        $observations = [];

        if ($vital->bp_systolic !== null) {
            $observations[] = self::makeObservation($vital, '8480-6', $vital->bp_systolic);
        }
        if ($vital->bp_diastolic !== null) {
            $observations[] = self::makeObservation($vital, '8462-4', $vital->bp_diastolic);
        }
        if ($vital->weight_lbs !== null) {
            // FHIR standard unit for weight is kg; convert from lbs
            $kg = round((float) $vital->weight_lbs * 0.453592, 2);
            $observations[] = self::makeObservation($vital, '29463-7', $kg, 'kg');
        }
        if ($vital->pulse !== null) {
            $observations[] = self::makeObservation($vital, '8867-4', $vital->pulse);
        }
        if ($vital->o2_saturation !== null) {
            $observations[] = self::makeObservation($vital, '59408-5', $vital->o2_saturation);
        }
        if ($vital->temperature_f !== null) {
            // FHIR standard unit for temperature is Celsius; convert from Fahrenheit
            $celsius = round(((float) $vital->temperature_f - 32) * 5 / 9, 2);
            $observations[] = self::makeObservation($vital, '8310-5', $celsius, 'Cel');
        }

        return $observations;
    }

    /**
     * Map a single Vital to the most common single Observation (bp_systolic).
     * Convenience method for unit tests.
     */
    public static function toFhir(Vital $vital): array
    {
        // Returns the first observation (bp_systolic if available, else first non-null)
        $collection = self::toFhirCollection($vital);
        return $collection[0] ?? [];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Build a FHIR R4 Observation resource. */
    private static function makeObservation(
        Vital $vital,
        string $loincCode,
        float|int $value,
        ?string $unitOverride = null,
    ): array {
        [$display, $defaultUnit] = self::LOINC_MAP[$loincCode];
        $unit = $unitOverride ?? $defaultUnit;

        $recordedAt = $vital->recorded_at instanceof \Carbon\Carbon
            ? $vital->recorded_at->toIso8601String()
            : now()->toIso8601String();

        return [
            'resourceType' => 'Observation',
            'id'           => "vital-{$vital->id}-{$loincCode}",
            'status'       => 'final',
            'category'     => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'vital-signs',
                            'display' => 'Vital Signs',
                        ],
                    ],
                ],
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => 'http://loinc.org',
                        'code'    => $loincCode,
                        'display' => $display,
                    ],
                ],
                'text' => $display,
            ],
            'subject' => [
                'reference' => "Patient/{$vital->participant_id}",
            ],
            'effectiveDateTime' => $recordedAt,
            'valueQuantity' => [
                'value'  => $value,
                'unit'   => $unit,
                'system' => 'http://unitsofmeasure.org',
                'code'   => $unit,
            ],
        ];
    }
}
