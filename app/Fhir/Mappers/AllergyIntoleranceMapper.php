<?php

// ─── AllergyIntoleranceMapper ─────────────────────────────────────────────────
// Maps a NostosEMR Allergy to a FHIR R4 AllergyIntolerance resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/allergyintolerance.html
//
// Severity mapping:
//   life_threatening → severe
//   severe           → severe
//   moderate         → moderate
//   mild             → mild
//
// Category mapping:
//   medication       → medication
//   food             → food
//   environmental    → environment
//   latex            → biologic
//   other            → (no category)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Allergy;

class AllergyIntoleranceMapper
{
    /**
     * Map an Allergy model to a FHIR R4 AllergyIntolerance resource.
     */
    public static function toFhir(Allergy $allergy): array
    {
        $recordedAt = $allergy->created_at instanceof \Carbon\Carbon
            ? $allergy->created_at->toIso8601String()
            : null;

        return [
            'resourceType'     => 'AllergyIntolerance',
            'id'               => (string) $allergy->id,
            'clinicalStatus'   => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                        'code'    => 'active',
                        'display' => 'Active',
                    ],
                ],
            ],
            'verificationStatus' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                        'code'    => 'confirmed',
                        'display' => 'Confirmed',
                    ],
                ],
            ],

            // ── Type and category ─────────────────────────────────────────────
            'type'     => 'allergy',
            'category' => array_filter([self::mapCategory($allergy->allergy_type)]),

            // ── Criticality ───────────────────────────────────────────────────
            'criticality' => $allergy->severity === 'life_threatening' ? 'high' : 'low',

            // ── Substance ─────────────────────────────────────────────────────
            'code' => [
                'coding' => [],
                'text'   => $allergy->allergen_name,
            ],

            // ── Patient ───────────────────────────────────────────────────────
            'patient' => [
                'reference' => "Patient/{$allergy->participant_id}",
            ],

            // ── Recorded date ─────────────────────────────────────────────────
            'recordedDate' => $recordedAt,

            // ── Reaction ─────────────────────────────────────────────────────
            'reaction' => $allergy->reaction_description ? [
                [
                    'description' => $allergy->reaction_description,
                    'severity'    => self::mapSeverity($allergy->severity),
                ],
            ] : null,
        ];
    }

    /** Map NostosEMR severity to FHIR AllergyIntolerance.reaction.severity. */
    private static function mapSeverity(?string $severity): string
    {
        return match ($severity) {
            'life_threatening', 'severe' => 'severe',
            'moderate'                   => 'moderate',
            'mild'                       => 'mild',
            default                      => 'mild',
        };
    }

    /** Map NostosEMR allergy_type to FHIR category code. */
    private static function mapCategory(?string $allergyType): ?string
    {
        return match ($allergyType) {
            'medication'    => 'medication',
            'food'          => 'food',
            'environmental' => 'environment',
            'latex'         => 'biologic',
            default         => null,
        };
    }
}
