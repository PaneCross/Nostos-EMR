<?php

// ─── ProcedureMapper ──────────────────────────────────────────────────────────
// Maps a NostosEMR Procedure to a FHIR R4 Procedure resource.
// FHIR R4 spec: https://hl7.org/fhir/R4/procedure.html
//
// code: CPT (HCPCS) preferred; falls back to SNOMED CT if no CPT.
// status: completed (all stored procedures are historical).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Procedure;

class ProcedureMapper
{
    private const CPT_SYSTEM    = 'http://www.ama-assn.org/go/cpt';
    private const SNOMED_SYSTEM = 'http://snomed.info/sct';

    /**
     * Map a Procedure model to a FHIR R4 Procedure resource array.
     */
    public static function toFhir(Procedure $procedure): array
    {
        $codings = [];
        if ($procedure->cpt_code) {
            $codings[] = [
                'system'  => self::CPT_SYSTEM,
                'code'    => $procedure->cpt_code,
                'display' => $procedure->procedure_name,
            ];
        }
        if ($procedure->snomed_code) {
            $codings[] = [
                'system'  => self::SNOMED_SYSTEM,
                'code'    => $procedure->snomed_code,
                'display' => $procedure->procedure_name,
            ];
        }

        $resource = [
            'resourceType' => 'Procedure',
            'id'           => (string) $procedure->id,
            'status'       => 'completed',

            'code' => [
                'coding' => $codings ?: [],
                'text'   => $procedure->procedure_name,
            ],

            'subject' => [
                'reference' => "Patient/{$procedure->participant_id}",
            ],

            'performedDateTime' => $procedure->performed_date->format('Y-m-d'),
        ];

        if ($procedure->facility) {
            $resource['location'] = ['display' => $procedure->facility];
        }

        if ($procedure->body_site) {
            $resource['bodySite'] = [
                [
                    'text' => $procedure->body_site,
                ],
            ];
        }

        if ($procedure->outcome) {
            $resource['outcome'] = ['text' => $procedure->outcome];
        }

        if ($procedure->notes) {
            $resource['note'] = [['text' => $procedure->notes]];
        }

        // Indicate data provenance source
        $resource['extension'][] = [
            'url'         => 'http://nostosemr.com/fhir/StructureDefinition/procedure-source',
            'valueString' => $procedure->source,
        ];

        return $resource;
    }
}
