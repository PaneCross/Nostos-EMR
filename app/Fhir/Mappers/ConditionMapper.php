<?php

// ─── ConditionMapper ──────────────────────────────────────────────────────────
// Maps a NostosEMR Problem to a FHIR R4 Condition resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/condition.html
//
// Clinical status mapping:
//   active       → "active"
//   resolved     → "resolved"
//   inactive     → "inactive"
//
// The ICD-10 code from the problem list maps to condition.code using the SNOMED CT
// system identifier (ICD-10-CM is used in the EMR; mapped to standard codings).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Problem;

class ConditionMapper
{
    /**
     * Map a Problem model to a FHIR R4 Condition resource.
     */
    public static function toFhir(Problem $problem): array
    {
        $onsetAt = $problem->onset_date instanceof \Carbon\Carbon
            ? $problem->onset_date->format('Y-m-d')
            : null;

        $resolvedAt = $problem->resolved_date instanceof \Carbon\Carbon
            ? $problem->resolved_date->format('Y-m-d')
            : null;

        return [
            'resourceType' => 'Condition',
            'id'           => (string) $problem->id,

            // ── Clinical Status ───────────────────────────────────────────────
            'clinicalStatus' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                        'code'    => self::mapClinicalStatus($problem->status),
                        'display' => ucfirst($problem->status ?? 'active'),
                    ],
                ],
            ],

            // ── ICD-10 Code ───────────────────────────────────────────────────
            'code' => [
                'coding' => [
                    [
                        'system'  => 'http://hl7.org/fhir/sid/icd-10-cm',
                        'code'    => $problem->icd10_code,
                        'display' => $problem->description,
                    ],
                ],
                'text' => $problem->description,
            ],

            // ── Subject ───────────────────────────────────────────────────────
            'subject' => [
                'reference' => "Patient/{$problem->participant_id}",
            ],

            // ── Timing ───────────────────────────────────────────────────────
            'onsetDateTime'    => $onsetAt,
            'abatementDateTime'=> $resolvedAt,

            // ── Category ─────────────────────────────────────────────────────
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                            'code'    => 'problem-list-item',
                            'display' => 'Problem List Item',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Map NostosEMR problem status to FHIR clinical-status code. */
    private static function mapClinicalStatus(?string $status): string
    {
        return match ($status) {
            'active'   => 'active',
            'resolved' => 'resolved',
            'inactive' => 'inactive',
            default    => 'active',
        };
    }
}
