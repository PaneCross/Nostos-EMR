<?php

// ─── AppointmentMapper ────────────────────────────────────────────────────────
// Maps a NostosEMR Appointment to a FHIR R4 Appointment resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/appointment.html
//
// Status mapping:
//   scheduled  → booked
//   confirmed  → booked
//   completed  → fulfilled
//   cancelled  → cancelled
//   no_show    → noshow
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Appointment;

class AppointmentMapper
{
    /**
     * Map an Appointment model to a FHIR R4 Appointment resource.
     */
    public static function toFhir(Appointment $appointment): array
    {
        $start = $appointment->scheduled_start instanceof \Carbon\Carbon
            ? $appointment->scheduled_start->toIso8601String()
            : null;
        $end = $appointment->scheduled_end instanceof \Carbon\Carbon
            ? $appointment->scheduled_end->toIso8601String()
            : null;

        return [
            'resourceType' => 'Appointment',
            'id'           => (string) $appointment->id,
            'status'       => self::mapStatus($appointment->status),

            // ── Service type ──────────────────────────────────────────────────
            'serviceType' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://nostosemr.com/fhir/CodeSystem/appointment-type',
                            'code'    => $appointment->appointment_type,
                            'display' => ucfirst(str_replace('_', ' ', $appointment->appointment_type ?? '')),
                        ],
                    ],
                ],
            ],

            // ── Timing ───────────────────────────────────────────────────────
            'start' => $start,
            'end'   => $end,

            // ── Participant ───────────────────────────────────────────────────
            'participant' => [
                [
                    'actor'  => ['reference' => "Patient/{$appointment->participant_id}"],
                    'status' => 'accepted',
                ],
            ],

            // ── Location ─────────────────────────────────────────────────────
            'contained' => $appointment->location_id ? [
                [
                    'resourceType' => 'Location',
                    'id'           => "location-{$appointment->location_id}",
                ],
            ] : [],

            // ── Notes ─────────────────────────────────────────────────────────
            'comment' => $appointment->notes ?? null,
        ];
    }

    /** Map NostosEMR appointment status to FHIR Appointment status. */
    private static function mapStatus(?string $status): string
    {
        return match ($status) {
            'scheduled' => 'booked',
            'confirmed' => 'booked',
            'completed' => 'fulfilled',
            'cancelled' => 'cancelled',
            'no_show'   => 'noshow',
            default     => 'proposed',
        };
    }
}
