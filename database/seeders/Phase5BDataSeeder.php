<?php

// ─── Phase5BDataSeeder ─────────────────────────────────────────────────────────
// Seeds transport request demo data for Phase 5B (Transport Manifest & Add-On Queue).
//
// Seeds per tenant:
//   - 2–4 completed transport requests (past trips, various trip types)
//   - 3–6 scheduled/requested trips for today (mix of to_center, from_center, external_appt)
//   - 1–3 pending add-on requests (status=requested, trip_type=add_on)
//   - Mobility flags snapshot captured from participant's active flags at seed time
//
// Dependencies: Phase5ADataSeeder must run first (locations are seeded there).
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TransportRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase5BDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        // ── Fetch seeded dependencies ──────────────────────────────────────────
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()
            ->limit(20)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('  No enrolled participants found — skipping Phase 5B seeder.');
            return;
        }

        // Use the transportation department user for transport requests
        $transportUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'transportation')
            ->first();

        // Fall back to any active user if no transport user seeded
        if (! $transportUser) {
            $transportUser = User::where('tenant_id', $tenant->id)->where('is_active', true)->first();
        }

        // Any staff user for add-on requests (simulates cross-dept requests)
        $primaryCareUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first() ?? $transportUser;

        // PACE center locations for to_center/from_center trips
        $paceCenterLocations = Location::where('tenant_id', $tenant->id)
            ->where('location_type', 'pace_center')
            ->get();

        // Non-PACE locations for external appointments
        $externalLocations = Location::where('tenant_id', $tenant->id)
            ->where('location_type', '!=', 'pace_center')
            ->get();

        if ($paceCenterLocations->isEmpty()) {
            $this->command->warn('  No PACE center locations found — skipping Phase 5B seeder.');
            return;
        }

        $this->command->line('  Creating transport requests for ' . $participants->count() . ' participants...');

        // ── Past completed trips (2–4 per participant subset) ─────────────────
        $pastParticipants = $participants->take(12);
        foreach ($pastParticipants as $participant) {
            $count = random_int(1, 2);
            for ($i = 0; $i < $count; $i++) {
                $this->seedPastTrip($participant, $tenant->id, $transportUser, $paceCenterLocations, $externalLocations);
            }
        }

        // ── Today's run sheet (scheduled trips) ────────────────────────────────
        $todayParticipants = $participants->take(10);
        foreach ($todayParticipants as $participant) {
            $this->seedTodayTrip($participant, $tenant->id, $transportUser, $paceCenterLocations, $externalLocations);
        }

        // ── Pending add-on requests ────────────────────────────────────────────
        $addOnParticipants = $participants->reverse()->take(4);
        foreach ($addOnParticipants as $participant) {
            $this->seedAddOnRequest($participant, $tenant->id, $primaryCareUser, $paceCenterLocations, $externalLocations);
        }

        $total = TransportRequest::where('tenant_id', $tenant->id)->count();
        $this->command->line("  Phase 5B seeder complete — {$total} transport requests created.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Seed a completed past trip (demonstrates historical manifest data).
     */
    private function seedPastTrip(
        Participant $participant,
        int $tenantId,
        User $user,
        $paceCenterLocations,
        $externalLocations,
    ): void {
        $daysAgo   = random_int(1, 14);
        $pickupHour = random_int(7, 10); // Morning pickups typical for PACE
        $pickup    = Carbon::today()->subDays($daysAgo)->setHour($pickupHour)->setMinute(0);
        $dropoff   = $pickup->copy()->addMinutes(random_int(30, 60));

        $tripType   = collect(['to_center', 'from_center', 'external_appt'])->random();
        [$pickupLoc, $dropoffLoc] = $this->pickLocationsForType($tripType, $paceCenterLocations, $externalLocations);

        if (! $pickupLoc || ! $dropoffLoc) return;

        $flags = $this->snapshotFlags($participant);

        TransportRequest::create([
            'tenant_id'              => $tenantId,
            'participant_id'         => $participant->id,
            'requesting_user_id'     => $user->id,
            'requesting_department'  => 'transportation',
            'trip_type'              => $tripType,
            'pickup_location_id'     => $pickupLoc->id,
            'dropoff_location_id'    => $dropoffLoc->id,
            'requested_pickup_time'  => $pickup,
            'scheduled_pickup_time'  => $pickup,
            'actual_pickup_time'     => $pickup->copy()->addMinutes(random_int(-5, 10)),
            'actual_dropoff_time'    => $dropoff,
            'special_instructions'   => null,
            'mobility_flags_snapshot' => $flags,
            'status'                 => 'completed',
            'transport_trip_id'      => random_int(1000, 9999),
            'driver_notes'           => collect([null, null, 'On time', 'Slight delay — traffic'])->random(),
            'last_synced_at'         => now(),
        ]);
    }

    /**
     * Seed a today's scheduled trip for the run sheet.
     * Mix of statuses (scheduled, en_route, arrived) to demo real-time manifest.
     */
    private function seedTodayTrip(
        Participant $participant,
        int $tenantId,
        User $user,
        $paceCenterLocations,
        $externalLocations,
    ): void {
        $pickupHour = random_int(6, 14);
        $pickup     = Carbon::today()->setHour($pickupHour)->setMinute(random_int(0, 1) * 30);

        $tripType   = collect(['to_center', 'from_center'])->random();
        [$pickupLoc, $dropoffLoc] = $this->pickLocationsForType($tripType, $paceCenterLocations, $externalLocations);

        if (! $pickupLoc || ! $dropoffLoc) return;

        $flags  = $this->snapshotFlags($participant);
        $status = $this->pickStatusForTime($pickup);

        TransportRequest::create([
            'tenant_id'              => $tenantId,
            'participant_id'         => $participant->id,
            'requesting_user_id'     => $user->id,
            'requesting_department'  => 'transportation',
            'trip_type'              => $tripType,
            'pickup_location_id'     => $pickupLoc->id,
            'dropoff_location_id'    => $dropoffLoc->id,
            'requested_pickup_time'  => $pickup,
            'scheduled_pickup_time'  => $pickup,
            'actual_pickup_time'     => in_array($status, ['arrived', 'completed']) ? $pickup->copy()->addMinutes(random_int(0, 10)) : null,
            'actual_dropoff_time'    => $status === 'completed' ? $pickup->copy()->addMinutes(45) : null,
            'special_instructions'   => collect([null, null, 'Needs 5 min pre-boarding'])->random(),
            'mobility_flags_snapshot' => $flags,
            'status'                 => $status,
            'transport_trip_id'      => in_array($status, ['scheduled', 'dispatched', 'en_route', 'arrived', 'completed']) ? random_int(1000, 9999) : null,
            'driver_notes'           => null,
            'last_synced_at'         => in_array($status, ['dispatched', 'en_route', 'arrived', 'completed']) ? now() : null,
        ]);
    }

    /**
     * Seed a pending add-on request (appears in the Add-On Queue tab).
     * Submitted by non-transport staff; awaiting Transportation Team approval.
     */
    private function seedAddOnRequest(
        Participant $participant,
        int $tenantId,
        User $requestingUser,
        $paceCenterLocations,
        $externalLocations,
    ): void {
        $pickup = Carbon::today()->setHour(random_int(10, 15))->setMinute(0);

        // Add-ons are most commonly to an external appointment or back from center
        $tripType   = 'add_on';
        $pickupLoc  = $externalLocations->isNotEmpty() ? $externalLocations->random() : $paceCenterLocations->first();
        $dropoffLoc = $paceCenterLocations->first();

        if (! $pickupLoc || ! $dropoffLoc) return;

        $flags = $this->snapshotFlags($participant);

        TransportRequest::create([
            'tenant_id'              => $tenantId,
            'participant_id'         => $participant->id,
            'requesting_user_id'     => $requestingUser->id,
            'requesting_department'  => $requestingUser->department,
            'trip_type'              => $tripType,
            'pickup_location_id'     => $pickupLoc->id,
            'dropoff_location_id'    => $dropoffLoc->id,
            'requested_pickup_time'  => $pickup,
            'scheduled_pickup_time'  => null,
            'actual_pickup_time'     => null,
            'actual_dropoff_time'    => null,
            'special_instructions'   => collect([
                'Doctor added urgent follow-up — needs immediate transport back',
                'Dialysis ran over — participant needs pickup ASAP',
                null,
            ])->random(),
            'mobility_flags_snapshot' => $flags,
            'status'                 => 'requested',
            'transport_trip_id'      => null,
            'driver_notes'           => null,
            'last_synced_at'         => null,
        ]);
    }

    /**
     * Pick pickup + dropoff locations appropriate for the given trip_type.
     * to_center  → pickup at home/external, dropoff at PACE center
     * from_center → pickup at PACE center, dropoff at home/external
     * external_appt → pickup at PACE center, dropoff at external location
     */
    private function pickLocationsForType(string $tripType, $paceLocations, $externalLocations): array
    {
        $pace     = $paceLocations->isNotEmpty() ? $paceLocations->random() : null;
        $external = $externalLocations->isNotEmpty() ? $externalLocations->random() : null;

        return match ($tripType) {
            'to_center'    => [$external ?? $pace, $pace],
            'from_center'  => [$pace, $external ?? $pace],
            'external_appt' => [$pace, $external ?? $pace],
            default        => [$pace, $external ?? $pace],
        };
    }

    /**
     * Build a mobility flags snapshot from the participant's current active flags.
     * The snapshot is stored at request time for historical accuracy on run sheets.
     */
    private function snapshotFlags(Participant $participant): array
    {
        // transportFlags() eager-loads or queries active transport-type flags
        $flags = DB::table('emr_participant_flags')
            ->where('participant_id', $participant->id)
            ->where('resolved_at', null)
            ->whereIn('flag_type', ['wheelchair', 'stretcher', 'oxygen', 'behavioral'])
            ->get(['flag_type', 'severity', 'description']);

        return $flags->map(fn ($f) => [
            'type'        => $f->flag_type,
            'severity'    => $f->severity,
            'description' => $f->description,
        ])->values()->toArray();
    }

    /**
     * Choose a realistic status for a today trip based on scheduled pickup time.
     * Morning trips completed by afternoon; afternoon trips still en_route or scheduled.
     */
    private function pickStatusForTime(Carbon $pickup): string
    {
        $now = Carbon::now();

        if ($pickup > $now) {
            // Future pickup → scheduled
            return 'scheduled';
        }

        $minutesAgo = $now->diffInMinutes($pickup);

        if ($minutesAgo > 90) {
            // Well past pickup time → completed or no_show (80/20)
            return collect(['completed', 'completed', 'completed', 'completed', 'no_show'])->random();
        }

        if ($minutesAgo > 30) {
            return collect(['en_route', 'arrived', 'completed'])->random();
        }

        return collect(['dispatched', 'en_route'])->random();
    }
}
