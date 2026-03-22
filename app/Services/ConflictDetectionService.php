<?php

// ─── ConflictDetectionService ──────────────────────────────────────────────────
// Prevents double-booking of PACE participants across all appointment types.
//
// Two types of conflict checks:
//
//   1. checkParticipantConflict — standard overlap detection.
//      A new appointment overlaps an existing one if:
//        existing.start < new.end  AND  existing.end > new.start
//      Cancelled appointments are excluded (they no longer block the slot).
//
//   2. checkTransportConflict — transport window detection.
//      Transport scheduling requires a 2-hour buffer around each appointment
//      that needs transport. This prevents scheduling two transport-required
//      appointments so close together that the vehicle cannot return in time.
//      Window: [$requestedTime - 2h, $requestedTime + 2h]
//
// Both methods accept an optional $excludeId so an existing appointment can
// be updated without conflicting with itself.
//
// Used by: AppointmentController (store + update), AppointmentTest.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;

class ConflictDetectionService
{
    /**
     * Check whether a time range overlaps any existing active appointment for
     * the given participant.
     *
     * Overlap condition (half-open intervals):
     *   existing.start < $end  AND  existing.end > $start
     *
     * Cancelled appointments are excluded — they no longer block the slot.
     *
     * @param  int       $participantId  Participant to check conflicts for.
     * @param  Carbon    $start          Proposed appointment start time.
     * @param  Carbon    $end            Proposed appointment end time.
     * @param  int|null  $excludeId      Appointment ID to exclude (for updates).
     * @return bool  True if a conflict exists (booking should be blocked).
     */
    public function checkParticipantConflict(
        int $participantId,
        Carbon $start,
        Carbon $end,
        ?int $excludeId = null
    ): bool {
        $query = Appointment::where('participant_id', $participantId)
            ->notCancelled()
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Check whether the requested transport time falls within 2 hours of any
     * existing transport-required appointment for the same participant.
     *
     * The 2-hour buffer ensures the vehicle can complete the current trip and
     * return to pick up the participant for the next transport-required appointment.
     *
     * Window checked: [$requestedTime - 2h, $requestedTime + 2h]
     *
     * @param  int       $participantId   Participant to check.
     * @param  Carbon    $requestedTime   The scheduled_start of the proposed appointment.
     * @param  int|null  $excludeId       Appointment ID to exclude (for updates).
     * @return bool  True if transport conflict exists (booking should be blocked).
     */
    public function checkTransportConflict(
        int $participantId,
        Carbon $requestedTime,
        ?int $excludeId = null
    ): bool {
        $windowStart = $requestedTime->copy()->subHours(2);
        $windowEnd   = $requestedTime->copy()->addHours(2);

        $query = Appointment::where('participant_id', $participantId)
            ->notCancelled()
            ->where('transport_required', true)
            ->where('scheduled_start', '>=', $windowStart)
            ->where('scheduled_start', '<=', $windowEnd);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
