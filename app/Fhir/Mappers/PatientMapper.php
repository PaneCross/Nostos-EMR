<?php

// ─── PatientMapper ────────────────────────────────────────────────────────────
// Maps a NostosEMR Participant to a FHIR R4 Patient resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/patient.html
//
// Identifier coding:
//   MRN          → type.coding.code = "MR"  (Medical Record Number)
//   Medicare ID  → type.coding.code = "SB"  (Social Beneficiary Identifier)
//   Medicaid ID  → type.coding.code = "MA"  (Medicaid Account Number)
//
// Gender mapping: PACE uses 'female'/'male'/'non_binary'/'prefer_not_to_say'
//   FHIR uses: 'male'/'female'/'other'/'unknown'
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Participant;

class PatientMapper
{
    private const IDENTIFIER_SYSTEM = 'http://terminology.hl7.org/CodeSystem/v2-0203';

    /**
     * Map a Participant model to a FHIR R4 Patient resource array.
     * Load participant->addresses relation before calling if you want address data.
     */
    public static function toFhir(Participant $participant): array
    {
        return [
            'resourceType' => 'Patient',
            'id'           => (string) $participant->id,
            'meta'         => [
                'profile' => ['http://hl7.org/fhir/us/core/StructureDefinition/us-core-patient'],
            ],

            // ── Identifiers ────────────────────────────────────────────────────
            'identifier' => array_filter([
                // MRN
                self::makeIdentifier('MR', $participant->mrn),
                // Medicare ID (CMS Beneficiary ID)
                $participant->medicare_id
                    ? self::makeIdentifier('SB', $participant->medicare_id, 'http://hl7.org/fhir/sid/us-medicare')
                    : null,
                // Medicaid ID
                $participant->medicaid_id
                    ? self::makeIdentifier('MA', $participant->medicaid_id, 'http://hl7.org/fhir/sid/us-medicaid')
                    : null,
                // PACE contract ID
                $participant->pace_contract_id
                    ? self::makeIdentifier('RI', $participant->pace_contract_id)
                    : null,
            ]),

            // ── Name ──────────────────────────────────────────────────────────
            'name' => [
                [
                    'use'    => 'official',
                    'family' => $participant->last_name,
                    'given'  => array_filter([$participant->first_name, $participant->preferred_name]),
                ],
            ],

            // ── Demographics ──────────────────────────────────────────────────
            'gender'    => self::mapGender($participant->gender),
            'birthDate' => $participant->dob instanceof \Carbon\Carbon
                ? $participant->dob->format('Y-m-d')
                : (string) $participant->dob,

            // ── Communication (language preference) ───────────────────────────
            'communication' => [
                [
                    'language' => [
                        'coding' => [
                            [
                                'system' => 'urn:ietf:bcp:47',
                                'code'   => self::mapLanguage($participant->primary_language),
                            ],
                        ],
                        'text' => $participant->primary_language,
                    ],
                    'preferred' => true,
                ],
            ],

            // ── Active status ─────────────────────────────────────────────────
            'active' => (bool) $participant->is_active,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Build a FHIR identifier object. */
    private static function makeIdentifier(
        string $typeCode,
        string $value,
        string $system = 'http://terminology.hl7.org/CodeSystem/v2-0203',
    ): array {
        return [
            'type'  => [
                'coding' => [
                    [
                        'system' => self::IDENTIFIER_SYSTEM,
                        'code'   => $typeCode,
                    ],
                ],
            ],
            'system' => $system,
            'value'  => $value,
        ];
    }

    /** Map NostosEMR gender values to FHIR gender codes. */
    private static function mapGender(?string $gender): string
    {
        return match ($gender) {
            'male'              => 'male',
            'female'            => 'female',
            'non_binary'        => 'other',
            'prefer_not_to_say' => 'unknown',
            default             => 'unknown',
        };
    }

    /** Map common language names to BCP-47 language codes. */
    private static function mapLanguage(?string $language): string
    {
        return match ($language) {
            'English'    => 'en',
            'Spanish'    => 'es',
            'Korean'     => 'ko',
            'Mandarin'   => 'zh',
            'Tagalog'    => 'tl',
            'Armenian'   => 'hy',
            'Vietnamese' => 'vi',
            'Russian'    => 'ru',
            default      => 'en',
        };
    }
}
