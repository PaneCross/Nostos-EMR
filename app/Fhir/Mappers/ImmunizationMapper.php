<?php

// ─── ImmunizationMapper ────────────────────────────────────────────────────────
// Maps a NostosEMR Immunization to a FHIR R4 Immunization resource.
// FHIR R4 spec: https://hl7.org/fhir/R4/immunization.html
//
// CVX coding system: http://hl7.org/fhir/sid/cvx
// Status: completed | not-done (refused)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Immunization;

class ImmunizationMapper
{
    private const CVX_SYSTEM = 'http://hl7.org/fhir/sid/cvx';

    /**
     * Map an Immunization model to a FHIR R4 Immunization resource array.
     */
    public static function toFhir(Immunization $immunization): array
    {
        $resource = [
            'resourceType' => 'Immunization',
            'id'           => (string) $immunization->id,
            'status'       => $immunization->refused ? 'not-done' : 'completed',

            'vaccineCode' => [
                'coding' => array_filter([
                    $immunization->resolvedCvxCode() ? [
                        'system'  => self::CVX_SYSTEM,
                        'code'    => $immunization->resolvedCvxCode(),
                        'display' => $immunization->vaccine_name,
                    ] : null,
                ]),
                'text' => $immunization->vaccine_name,
            ],

            'patient' => [
                'reference' => "Patient/{$immunization->participant_id}",
            ],

            'occurrenceDateTime' => $immunization->administered_date->format('Y-m-d'),
            'recorded'           => $immunization->created_at->toIso8601String(),
            'primarySource'      => ! $immunization->refused,
        ];

        // Lot number
        if ($immunization->lot_number) {
            $resource['lotNumber'] = $immunization->lot_number;
        }

        // Manufacturer
        if ($immunization->manufacturer) {
            $resource['manufacturer'] = ['display' => $immunization->manufacturer];
        }

        // Site of administration
        if ($immunization->administered_at_location) {
            $resource['location'] = ['display' => $immunization->administered_at_location];
        }

        // Refusal reason
        if ($immunization->refused && $immunization->refusal_reason) {
            $resource['statusReason'] = [
                'text' => $immunization->refusal_reason,
            ];
        }

        // Protocol / dose number
        if ($immunization->dose_number) {
            $resource['protocolApplied'] = [
                ['doseNumberPositiveInt' => $immunization->dose_number],
            ];
        }

        return $resource;
    }
}
