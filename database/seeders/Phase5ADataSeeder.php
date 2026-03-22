<?php

// ─── Phase5ADataSeeder ─────────────────────────────────────────────────────────
// Seeds location and appointment demo data for Phase 5A (Scheduling + Locations).
//
// Seeds:
//   - 10 locations per tenant (2 PACE centers, 2 dialysis, 3 specialists, 1 hospital, 2 labs)
//   - 3–7 appointments per participant (mix of past/future, types, statuses)
//
// Dependencies: DemoEnvironmentSeeder must run first (participants, users, sites needed).
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class Phase5ADataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        // ── Locations ─────────────────────────────────────────────────────────
        $this->command->line('  Creating 10 demo locations...');
        $locations = $this->seedLocations($tenant->id);

        // ── Appointments ──────────────────────────────────────────────────────
        $this->command->line('  Creating appointments for all participants...');
        $this->seedAppointments($tenant->id, $locations);
    }

    private function seedLocations(int $tenantId): array
    {
        $locationData = [
            // 2 PACE centers (same as sites)
            ['type' => 'pace_center',   'name' => 'Sunrise PACE East',               'street' => '1200 E Harbor Blvd',    'city' => 'Long Beach',   'state' => 'CA', 'zip' => '90802', 'phone' => '(562) 555-0100'],
            ['type' => 'pace_center',   'name' => 'Sunrise PACE West',               'street' => '4400 W Century Blvd',   'city' => 'Inglewood',    'state' => 'CA', 'zip' => '90304', 'phone' => '(310) 555-0200'],
            // 2 dialysis centers
            ['type' => 'dialysis',      'name' => 'DaVita Dialysis Long Beach',      'street' => '2340 Pacific Coast Hwy', 'city' => 'Long Beach',   'state' => 'CA', 'zip' => '90804', 'phone' => '(562) 555-0311'],
            ['type' => 'dialysis',      'name' => 'Fresenius Kidney Care Inglewood', 'street' => '801 N La Brea Ave',      'city' => 'Inglewood',    'state' => 'CA', 'zip' => '90302', 'phone' => '(310) 555-0412'],
            // 3 specialists
            ['type' => 'specialist',    'name' => 'Central Cardiology Associates',   'street' => '3800 Wilshire Blvd',    'city' => 'Los Angeles',  'state' => 'CA', 'zip' => '90010', 'phone' => '(213) 555-0521'],
            ['type' => 'specialist',    'name' => 'Valley Pulmonology Group',        'street' => '14600 Sherman Way',     'city' => 'Van Nuys',     'state' => 'CA', 'zip' => '91405', 'phone' => '(818) 555-0632'],
            ['type' => 'specialist',    'name' => 'South Bay Neurology Partners',    'street' => '22720 Crenshaw Blvd',   'city' => 'Torrance',     'state' => 'CA', 'zip' => '90505', 'phone' => '(310) 555-0743'],
            // 1 hospital
            ['type' => 'hospital',      'name' => 'Community Medical Center',        'street' => '1720 Termino Ave',      'city' => 'Long Beach',   'state' => 'CA', 'zip' => '90804', 'phone' => '(562) 555-0854'],
            // 2 labs
            ['type' => 'lab',           'name' => 'Quest Diagnostics Long Beach',    'street' => '3816 Woodruff Ave',     'city' => 'Long Beach',   'state' => 'CA', 'zip' => '90808', 'phone' => '(562) 555-0965'],
            ['type' => 'lab',           'name' => 'LabCorp Patient Service Center',  'street' => '1625 W Manchester Ave', 'city' => 'Inglewood',    'state' => 'CA', 'zip' => '90301', 'phone' => '(310) 555-0176'],
        ];

        $created = [];
        foreach ($locationData as $data) {
            $location = Location::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                [
                    'location_type' => $data['type'],
                    'street'        => $data['street'],
                    'city'          => $data['city'],
                    'state'         => $data['state'],
                    'zip'           => $data['zip'],
                    'phone'         => $data['phone'],
                    'is_active'     => true,
                ]
            );
            $created[] = $location;
        }

        return $created;
    }

    private function seedAppointments(int $tenantId, array $locations): void
    {
        $participants = Participant::where('tenant_id', $tenantId)->get();
        $providers    = User::where('tenant_id', $tenantId)
            ->whereIn('department', ['primary_care', 'therapies', 'social_work'])
            ->get();

        $paceLocations = collect($locations)->filter(fn ($l) => $l->location_type === 'pace_center')->values();
        $otherLocations = collect($locations)->filter(fn ($l) => $l->location_type !== 'pace_center')->values();

        $creatorUser = User::where('tenant_id', $tenantId)
            ->where('department', 'idt')
            ->first() ?? $providers->first();

        $appointmentCount = 0;

        foreach ($participants as $participant) {
            $count = rand(3, 7);

            for ($i = 0; $i < $count; $i++) {
                // Mix of past and future appointments, weighted toward recent history
                $isPast = $i < 3;
                if ($isPast) {
                    $dayOffset = -rand(1, 60);
                } else {
                    $dayOffset = rand(1, 30);
                }

                $start = Carbon::today()
                    ->addDays($dayOffset)
                    ->setHour(rand(8, 16))
                    ->setMinute(rand(0, 1) * 30)
                    ->setSecond(0);

                $durationMinutes = collect([30, 45, 60, 90])->random();
                $end = $start->copy()->addMinutes($durationMinutes);

                // Vary appointment types — PACE participants typically get a mix
                $type = collect(Appointment::APPOINTMENT_TYPES)->random();

                // Use PACE center locations for in-center visits, external for others
                $usePaceLocation = in_array($type, ['clinic_visit', 'therapy_pt', 'therapy_ot', 'therapy_st', 'activities', 'day_center_attendance']);
                $location = $usePaceLocation
                    ? $paceLocations->random()
                    : ($otherLocations->count() > 0 ? $otherLocations->random() : $paceLocations->random());

                // Status: past appts are completed; future are scheduled or confirmed
                if ($isPast) {
                    $status = collect(['completed', 'completed', 'completed', 'no_show', 'cancelled'])->random();
                } else {
                    $status = collect(['scheduled', 'scheduled', 'confirmed'])->random();
                }

                $data = [
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenantId,
                    'site_id'             => $participant->site_id,
                    'appointment_type'    => $type,
                    'provider_user_id'    => $providers->isNotEmpty() ? $providers->random()->id : null,
                    'location_id'         => $location->id,
                    'scheduled_start'     => $start,
                    'scheduled_end'       => $end,
                    'status'              => $status,
                    'transport_required'  => (bool) rand(0, 2) === 0, // ~33% need transport
                    'notes'               => null,
                    'cancellation_reason' => $status === 'cancelled' ? 'Participant requested reschedule' : null,
                    'created_by_user_id'  => $creatorUser->id,
                ];

                Appointment::create($data);
                $appointmentCount++;
            }
        }

        $this->command->line("  Created {$appointmentCount} appointments for {$participants->count()} participants.");
    }
}
