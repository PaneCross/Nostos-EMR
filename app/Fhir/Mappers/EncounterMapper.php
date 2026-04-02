<?php

// ─── EncounterMapper ──────────────────────────────────────────────────────────
// Maps a NostosEMR Appointment to a FHIR R4 Encounter resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/encounter.html
//
// Status mapping (Appointment → FHIR Encounter):
//   scheduled  → planned   (appointment booked, not yet started)
//   confirmed  → arrived   (participant confirmed / checked in)
//   completed  → finished  (encounter concluded)
//   cancelled  → cancelled (appointment cancelled before occurring)
//   no_show    → entered-in-error (participant did not arrive)
//
// Class mapping (appointment_type → ActCode):
//   home_visit → HH  (home health)
//   telehealth → VR  (virtual)
//   all others → AMB (ambulatory — PACE day center default)
//
// W4-9 — GAP-13: FHIR R4 Encounter resource.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Appointment;

class EncounterMapper
{
    /**
     * Map an Appointment to a FHIR R4 Encounter resource array.
     */
    public static function toFhir(Appointment $appointment): array
    {
        return [
            'resourceType' => 'Encounter',
            'id'           => (string) $appointment->id,
            'meta'         => [
                'profile' => ['http://hl7.org/fhir/us/core/StructureDefinition/us-core-encounter'],
            ],

            // ── Status ────────────────────────────────────────────────────────
            'status' => self::mapStatus($appointment->status),

            // ── Class (ActCode) ───────────────────────────────────────────────
            'class' => self::mapClass($appointment->appointment_type),

            // ── Type (appointment category) ───────────────────────────────────
            'type' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://snomed.info/sct',
                            'display' => $appointment->typeLabel(),
                        ],
                    ],
                    'text' => $appointment->typeLabel(),
                ],
            ],

            // ── Subject (participant) ─────────────────────────────────────────
            'subject' => ['reference' => "Patient/{$appointment->participant_id}"],

            // ── Participant (provider) ────────────────────────────────────────
            'participant' => $appointment->provider_user_id ? [
                [
                    'individual' => ['reference' => "Practitioner/{$appointment->provider_user_id}"],
                ],
            ] : [],

            // ── Period ────────────────────────────────────────────────────────
            'period' => array_filter([
                'start' => $appointment->scheduled_start?->toIso8601String(),
                'end'   => $appointment->scheduled_end?->toIso8601String(),
            ]),

            // ── Service provider (PACE site) ──────────────────────────────────
            'serviceProvider' => ['reference' => "Organization/site-{$appointment->site_id}"],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Map NostosEMR appointment status to FHIR Encounter status code. */
    private static function mapStatus(string $status): string
    {
        return match ($status) {
            'scheduled'  => 'planned',
            'confirmed'  => 'arrived',
            'completed'  => 'finished',
            'cancelled'  => 'cancelled',
            'no_show'    => 'entered-in-error',
            default      => 'unknown',
        };
    }

    /**
     * Map appointment_type to FHIR v3-ActCode class coding.
     * AMB (ambulatory) is the default for PACE day-center visits.
     * HH (home health) for home_visit appointments.
     * VR (virtual) for telehealth/phone appointments.
     */
    private static function mapClass(string $appointmentType): array
    {
        [$code, $display] = match ($appointmentType) {
            'home_visit'                => ['HH',  'home health'],
            'telehealth'                => ['VR',  'virtual'],
            default                     => ['AMB', 'ambulatory'],
        };

        return [
            'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
            'code'    => $code,
            'display' => $display,
        ];
    }
}
