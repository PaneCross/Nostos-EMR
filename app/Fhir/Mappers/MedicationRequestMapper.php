<?php

// ─── MedicationRequestMapper ──────────────────────────────────────────────────
// Maps a NostosEMR Medication to a FHIR R4 MedicationRequest resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/medicationrequest.html
//
// Status mapping:
//   active (not discontinued) → "active"
//   discontinued              → "stopped"
//
// Dosage is represented as free-text (dosageInstruction.text) since NostosEMR
// stores dosage as a string field, not a structured FHIR Dosage.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Medication;

class MedicationRequestMapper
{
    /**
     * Map a Medication model to a FHIR R4 MedicationRequest resource.
     */
    public static function toFhir(Medication $medication): array
    {
        // prescribed_date is a Carbon date (cast in model); convert to ISO-8601 string
        $prescribedAt = $medication->prescribed_date instanceof \Carbon\Carbon
            ? $medication->prescribed_date->toIso8601String()
            : null;

        $isDiscontinued = $medication->status === 'discontinued';

        return [
            'resourceType' => 'MedicationRequest',
            'id'           => (string) $medication->id,
            'status'       => $isDiscontinued ? 'stopped' : 'active',
            'intent'       => 'order',

            // ── Medication reference ───────────────────────────────────────────
            'medicationCodeableConcept' => [
                'coding' => array_filter([
                    $medication->rxnorm_code ? [
                        'system'  => 'http://www.nlm.nih.gov/research/umls/rxnorm',
                        'code'    => $medication->rxnorm_code,
                        'display' => $medication->drug_name,
                    ] : null,
                ]),
                'text' => $medication->drug_name,
            ],

            // ── Patient reference ─────────────────────────────────────────────
            'subject' => [
                'reference' => "Patient/{$medication->participant_id}",
            ],

            // ── Timing ────────────────────────────────────────────────────────
            'authoredOn' => $prescribedAt,

            // ── Requester (prescriber) ────────────────────────────────────────
            'requester' => $medication->prescribed_by_user_id ? [
                'reference' => "Practitioner/{$medication->prescribed_by_user_id}",
            ] : null,

            // ── Dosage ────────────────────────────────────────────────────────
            'dosageInstruction' => [
                [
                    'text' => trim(
                        ($medication->dosage ?? '') . ' ' .
                        ($medication->route ?? '') . ' ' .
                        ($medication->frequency ?? '')
                    ),
                ],
            ],

            // ── Discontinuation reason (extension) ───────────────────────────
            // NostosEMR does not store a discontinued_at timestamp; reason is free-text.
            'extension' => array_filter([
                $isDiscontinued && $medication->discontinued_reason ? [
                    'url'           => 'http://nostosemr.com/fhir/StructureDefinition/discontinuedReason',
                    'valueString'   => $medication->discontinued_reason,
                ] : null,
            ]),
        ];
    }
}
