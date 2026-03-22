<?php

// ─── MedicationScheduleService ────────────────────────────────────────────────
// Generates the daily eMAR (Electronic Medication Administration Record) for
// all participants across all tenants.
//
// Called by a Horizon-scheduled job at midnight each night to pre-populate
// emr_emar_records for the coming day. Each active, non-PRN medication gets
// one or more EmarRecord rows depending on frequency.
//
// PRN medications are intentionally excluded — PRN doses are charted on-demand
// by nurses when they administer, not pre-scheduled.
//
// Frequency-to-times mapping:
//   daily   → [08:00]
//   BID     → [08:00, 20:00]
//   TID     → [08:00, 14:00, 20:00]
//   QID     → [08:00, 12:00, 16:00, 20:00]
//   Q4H     → [06:00, 10:00, 14:00, 18:00, 22:00, 02:00]
//   Q6H     → [06:00, 12:00, 18:00, 00:00]
//   Q8H     → [06:00, 14:00, 22:00]
//   Q12H    → [08:00, 20:00]
//   weekly  → [08:00] (only on the medication's start_date day of week)
//   monthly → [08:00] (only on the medication's start_date day of month)
//   once    → [08:00] (only on medication's start_date)
//   PRN     → skipped (on-demand only)
//
// generateDailyMar() is idempotent: it skips medications that already have
// records for the target date to avoid duplicates on re-runs.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EmarRecord;
use App\Models\Medication;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MedicationScheduleService
{
    /**
     * Frequency to scheduled times map.
     * Times are in H:i (24-hour) format; will be combined with the target date.
     */
    private const FREQUENCY_TIMES = [
        'daily'   => ['08:00'],
        'BID'     => ['08:00', '20:00'],
        'TID'     => ['08:00', '14:00', '20:00'],
        'QID'     => ['08:00', '12:00', '16:00', '20:00'],
        'Q4H'     => ['06:00', '10:00', '14:00', '18:00', '22:00', '02:00'],
        'Q6H'     => ['06:00', '12:00', '18:00', '00:00'],
        'Q8H'     => ['06:00', '14:00', '22:00'],
        'Q12H'    => ['08:00', '20:00'],
        'weekly'  => ['08:00'],
        'monthly' => ['08:00'],
        'once'    => ['08:00'],
        'PRN'     => [],  // PRN doses are charted on-demand
    ];

    /**
     * Generate all eMAR records for a given date across all tenants and participants.
     * Called by the nightly Horizon job.
     *
     * @param  Carbon  $date  The date to generate MAR records for (typically tomorrow).
     * @return int  Number of EmarRecord rows created.
     */
    public function generateDailyMar(Carbon $date): int
    {
        $dateStr = $date->toDateString();
        $created = 0;

        // Process all active, schedulable medications for the target date
        Medication::where('status', 'active')
            ->where('is_prn', false)
            ->where('start_date', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                // Include medications with no end date, or end date >= target date
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $dateStr);
            })
            ->with('participant:id,tenant_id')
            ->chunk(200, function ($medications) use ($date, $dateStr, &$created) {
                foreach ($medications as $med) {
                    $times = $this->getScheduledTimesForDate($med, $date);

                    foreach ($times as $time) {
                        $scheduledTime = Carbon::parse("{$dateStr} {$time}");

                        // Idempotency check: skip if record already exists for this slot
                        $exists = EmarRecord::where('medication_id', $med->id)
                            ->where('scheduled_time', $scheduledTime)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        EmarRecord::create([
                            'participant_id' => $med->participant_id,
                            'medication_id'  => $med->id,
                            'tenant_id'      => $med->tenant_id,
                            'scheduled_time' => $scheduledTime,
                            'status'         => 'scheduled',
                        ]);

                        $created++;
                    }
                }
            });

        Log::info('MedicationScheduleService: daily MAR generated', [
            'date'    => $dateStr,
            'created' => $created,
        ]);

        return $created;
    }

    /**
     * Determine the scheduled administration times for a medication on a given date.
     * Handles frequency-specific rules (weekly/monthly/once day-of-week/month matching).
     *
     * @param  Medication  $med   The medication to schedule.
     * @param  Carbon      $date  The target date.
     * @return array  Array of time strings ('H:i') for this medication on this date.
     */
    public function getScheduledTimesForDate(Medication $med, Carbon $date): array
    {
        $frequency = $med->frequency;

        if (!$frequency || !isset(self::FREQUENCY_TIMES[$frequency])) {
            return [];
        }

        $times = self::FREQUENCY_TIMES[$frequency];

        // Weekly: only on the same day of week as the start_date
        if ($frequency === 'weekly') {
            $startDate = Carbon::parse($med->start_date);
            if ($date->dayOfWeek !== $startDate->dayOfWeek) {
                return [];
            }
        }

        // Monthly: only on the same day of month as the start_date
        if ($frequency === 'monthly') {
            $startDate = Carbon::parse($med->start_date);
            if ($date->day !== $startDate->day) {
                return [];
            }
        }

        // Once: only on the start_date itself
        if ($frequency === 'once') {
            if ($date->toDateString() !== Carbon::parse($med->start_date)->toDateString()) {
                return [];
            }
        }

        return $times;
    }
}
