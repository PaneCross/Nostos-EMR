<?php

// ─── W3TransferSeeder ─────────────────────────────────────────────────────────
// Seeds 3 demo participants with completed site transfers (East → West) for
// the W3-6 Site Transfer Data Integrity feature demonstration.
//
// What this creates:
//   - 3 participants from the East site get completed transfers to the West site
//   - Each participant gets clinical notes at both sites (pre- and post-transfer)
//   - Notes are stamped with the correct site_id so the site badge feature works
//   - Effective dates are staggered 60–90 days in the past
//
// Run via: DemoEnvironmentSeeder → $this->call(W3TransferSeeder::class)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class W3TransferSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $eastSite = Site::where('tenant_id', $tenant->id)
            ->where('name', 'Sunrise PACE East')
            ->firstOrFail();

        $westSite = Site::where('tenant_id', $tenant->id)
            ->where('name', 'Sunrise PACE West')
            ->firstOrFail();

        // Enrollment user for requested_by / approved_by
        $enrollmentUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'enrollment')
            ->first();

        // Primary care user for clinical notes authorship
        $primaryCareUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first();

        if (! $enrollmentUser || ! $primaryCareUser) {
            $this->command->warn('  W3TransferSeeder: Required demo users not found — skipping.');
            return;
        }

        // Grab 3 enrolled participants currently at East site who don't yet have transfers
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('site_id', $eastSite->id)
            ->where('enrollment_status', 'enrolled')
            ->whereDoesntHave('siteTransfers')
            ->take(3)
            ->get();

        if ($participants->count() < 3) {
            $this->command->warn('  W3TransferSeeder: Fewer than 3 eligible East-site participants — seeding what is available.');
        }

        // Effective dates: 60, 75, and 90 days ago so each transfer is clearly in the past
        $effectiveDates = [
            now()->subDays(60)->toDateString(),
            now()->subDays(75)->toDateString(),
            now()->subDays(90)->toDateString(),
        ];

        foreach ($participants as $i => $participant) {
            $effectiveDate = $effectiveDates[$i];

            DB::transaction(function () use (
                $participant, $tenant, $eastSite, $westSite,
                $enrollmentUser, $primaryCareUser, $effectiveDate
            ) {
                // ── 1. Create the completed site transfer record ──────────────
                ParticipantSiteTransfer::create([
                    'participant_id'        => $participant->id,
                    'tenant_id'             => $tenant->id,
                    'from_site_id'          => $eastSite->id,
                    'to_site_id'            => $westSite->id,
                    'transfer_reason'       => 'relocation',
                    'transfer_reason_notes' => 'Participant relocated closer to West site.',
                    'requested_by_user_id'  => $enrollmentUser->id,
                    'requested_at'          => now()->subDays(5)->addSeconds($participant->id),
                    'approved_by_user_id'   => $enrollmentUser->id,
                    'approved_at'           => now()->subDays(3)->addSeconds($participant->id),
                    'effective_date'        => $effectiveDate,
                    'status'                => 'completed',
                    'notification_sent'     => true,
                ]);

                // ── 2. Move participant to West site ──────────────────────────
                $participant->update(['site_id' => $westSite->id]);

                // ── 3. Clinical notes BEFORE transfer (East site) ─────────────
                // Two notes timestamped 2 weeks before the effective date
                foreach ([14, 7] as $daysBeforeTransfer) {
                    $noteDate = now()->subDays(
                        (int) (now()->diffInDays($effectiveDate)) + $daysBeforeTransfer
                    );
                    ClinicalNote::create([
                        'participant_id'      => $participant->id,
                        'tenant_id'           => $tenant->id,
                        'site_id'             => $eastSite->id,
                        'authored_by_user_id' => $primaryCareUser->id,
                        'department'          => 'primary_care',
                        'note_type'           => 'soap',
                        'visit_type'          => 'in_center',
                        'visit_date'          => $noteDate->toDateString(),
                        'status'              => 'signed',
                        'signed_at'           => $noteDate,
                        'signed_by_user_id'   => $primaryCareUser->id,
                        'content'             => json_encode([
                            'subjective' => 'Pre-transfer routine visit. Participant preparing for site change.',
                            'objective'  => 'Vitals stable. No acute concerns.',
                            'assessment' => 'Stable chronic conditions.',
                            'plan'       => 'Continue current care plan. Coordinate transfer to West site.',
                        ]),
                        'is_late_entry' => false,
                        'created_at'    => $noteDate,
                    ]);
                }

                // ── 4. Clinical notes AFTER transfer (West site) ──────────────
                // Two notes timestamped 1 and 3 weeks after the effective date
                foreach ([7, 21] as $daysAfterTransfer) {
                    $noteDate = now()->subDays(
                        max(0, (int) (now()->diffInDays($effectiveDate)) - $daysAfterTransfer)
                    );

                    ClinicalNote::create([
                        'participant_id'      => $participant->id,
                        'tenant_id'           => $tenant->id,
                        'site_id'             => $westSite->id,
                        'authored_by_user_id' => $primaryCareUser->id,
                        'department'          => 'primary_care',
                        'note_type'           => 'soap',
                        'visit_type'          => 'in_center',
                        'visit_date'          => $noteDate->toDateString(),
                        'status'              => 'signed',
                        'signed_at'           => $noteDate,
                        'signed_by_user_id'   => $primaryCareUser->id,
                        'content'             => json_encode([
                            'subjective' => 'Post-transfer follow-up. Participant settled into West site.',
                            'objective'  => 'Vitals stable. Adjustment going smoothly.',
                            'assessment' => 'Transition successful. No new concerns.',
                            'plan'       => 'Continue care plan at West site. Re-assess in 30 days.',
                        ]),
                        'is_late_entry' => false,
                        'created_at'    => $noteDate,
                    ]);
                }
            });

            $this->command->line(
                "  Transfer seeded: <comment>{$participant->first_name} {$participant->last_name}</comment>" .
                " East → West (effective {$effectiveDate})"
            );
        }

        $this->command->line('  <info>W3-6 transfer demo data complete (' . $participants->count() . ' participants).</info>');
    }
}
