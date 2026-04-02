<?php

// ─── PractitionerMapper ───────────────────────────────────────────────────────
// Maps a NostosEMR User (clinical department) to a FHIR R4 Practitioner resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/practitioner.html
//
// Only users in clinical departments are exposed as Practitioners.
// Non-clinical departments (finance, enrollment, qa_compliance, it_admin,
// transportation, activities, executive, super_admin) are excluded.
//
// NPI identifiers: NPI is not yet stored in NostosEMR (see HANDOFF.md DEBT section).
// The identifier array is intentionally empty until NPIs are collected at go-live.
//
// Qualification mapping (department → credential stub):
//   primary_care      → MD   (Physician/MD/DO/NP)
//   therapies         → PT   (Physical/Occupational/Speech Therapist)
//   social_work       → MSW  (Master of Social Work)
//   behavioral_health → MHC  (Mental Health Counselor)
//   dietary           → RD   (Registered Dietitian)
//   home_care         → RN   (Registered Nurse)
//   pharmacy          → PharmD (Doctor of Pharmacy)
//   idt               → OT   (Interdisciplinary Team)
//
// W4-9 — GAP-13: FHIR R4 Practitioner resource.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\User;

class PractitionerMapper
{
    /** Departments whose users are exposed as FHIR Practitioners. */
    public const CLINICAL_DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'home_care', 'pharmacy', 'idt',
    ];

    /**
     * Stub qualification codes by department.
     * NPI and formal credentialing data must be collected at go-live.
     */
    private const DEPT_QUALIFICATIONS = [
        'primary_care'       => ['code' => 'MD',     'display' => 'Physician (MD/DO/NP)'],
        'therapies'          => ['code' => 'PT',     'display' => 'Physical/Occupational/Speech Therapist'],
        'social_work'        => ['code' => 'MSW',    'display' => 'Social Worker (MSW)'],
        'behavioral_health'  => ['code' => 'MHC',    'display' => 'Mental Health Counselor'],
        'dietary'            => ['code' => 'RD',     'display' => 'Registered Dietitian (RD)'],
        'home_care'          => ['code' => 'RN',     'display' => 'Registered Nurse (RN)'],
        'pharmacy'           => ['code' => 'PharmD', 'display' => 'Pharmacist (PharmD)'],
        'idt'                => ['code' => 'OT',     'display' => 'Interdisciplinary Team Member'],
    ];

    /**
     * Map a User (clinical department) to a FHIR R4 Practitioner resource array.
     */
    public static function toFhir(User $user): array
    {
        $qual = self::DEPT_QUALIFICATIONS[$user->department]
            ?? ['code' => 'HEALTH', 'display' => 'Healthcare Professional'];

        return [
            'resourceType' => 'Practitioner',
            'id'           => (string) $user->id,

            // NPI stub — individual NPI not yet stored in NostosEMR.
            // Add npi column to shared_users at go-live and populate here.
            'identifier' => [],

            'active' => (bool) $user->is_active,

            // ── Name ──────────────────────────────────────────────────────────
            'name' => [
                [
                    'use'    => 'official',
                    'family' => $user->last_name,
                    'given'  => [$user->first_name],
                ],
            ],

            // ── Contact ───────────────────────────────────────────────────────
            'telecom' => [
                [
                    'system' => 'email',
                    'value'  => $user->email,
                    'use'    => 'work',
                ],
            ],

            // ── Qualification (department-derived credential stub) ─────────────
            'qualification' => [
                [
                    'code' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0360',
                                'code'    => $qual['code'],
                                'display' => $qual['display'],
                            ],
                        ],
                        'text' => $qual['display'],
                    ],
                ],
            ],
        ];
    }
}
