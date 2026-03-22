<?php

// ─── SdohObservationMapper ────────────────────────────────────────────────────
// Maps a NostosEMR SocialDeterminant to FHIR R4 Observation resources.
// FHIR R4 spec: https://hl7.org/fhir/R4/observation.html
//
// Each SDOH domain is mapped to a separate Observation with the appropriate
// LOINC code. The full screening produces a bundle of Observations.
//
// LOINC codes used:
//   Housing stability:     71802-3
//   Food security:         88122-7
//   Transportation access: 93030-5
//   Social isolation risk: 93029-7
//   Caregiver strain:      93038-8
//   Financial strain:      68517-2
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\SocialDeterminant;

class SdohObservationMapper
{
    private const LOINC_SYSTEM = 'http://loinc.org';

    /**
     * Map a SocialDeterminant record to a collection of FHIR R4 Observation arrays.
     * Returns one Observation per SDOH domain.
     *
     * @return array<int, array>
     */
    public static function toFhirCollection(SocialDeterminant $sdoh): array
    {
        $observations = [];
        $base         = "{$sdoh->id}-";

        foreach (SocialDeterminant::LOINC_CODES as $field => $loincCode) {
            $value = $sdoh->$field;
            if ($value === null) {
                continue;
            }

            $observations[] = [
                'resourceType' => 'Observation',
                'id'           => $base . $field,
                'status'       => 'final',
                'category'     => [
                    [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                                'code'    => 'social-history',
                                'display' => 'Social History',
                            ],
                        ],
                    ],
                ],
                'code' => [
                    'coding' => [
                        [
                            'system'  => self::LOINC_SYSTEM,
                            'code'    => $loincCode,
                            'display' => ucwords(str_replace('_', ' ', $field)),
                        ],
                    ],
                    'text' => ucwords(str_replace('_', ' ', $field)),
                ],
                'subject' => [
                    'reference' => "Patient/{$sdoh->participant_id}",
                ],
                'effectiveDateTime' => $sdoh->assessed_at->toIso8601String(),
                'valueString'       => $value,
            ];
        }

        return $observations;
    }
}
