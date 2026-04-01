<?php

// ─── ParticipantPhotoSeeder ────────────────────────────────────────────────────
// Seeds placeholder profile photos for enrolled participants so the photo upload
// feature is visually testable without manually uploading files.
//
// Uses pravatar.cc (free placeholder avatar service) to download distinct face
// images. Stores files to storage/app/public/participants/{id}/photo.jpg and
// updates photo_path on emr_participants.
//
// Run from WSL2:
//   docker compose exec -T laravel.test php artisan db:seed --class=ParticipantPhotoSeeder
//
// NOTE: Requires internet access from inside the container. If offline, the
// seeder skips unavailable images silently.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Participant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ParticipantPhotoSeeder extends Seeder
{
    // Number of participants to seed with photos (first N by ID)
    private const PHOTO_COUNT = 15;

    public function run(): void
    {
        $participants = Participant::whereIn('enrollment_status', ['enrolled', 'active'])
            ->orderBy('id')
            ->limit(self::PHOTO_COUNT)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('No enrolled participants found. Seed DemoEnvironmentSeeder first.');
            return;
        }

        $this->command->info("Seeding photos for {$participants->count()} participants...");

        foreach ($participants as $i => $participant) {
            // pravatar.cc/200?img=N — 70 distinct faces, numbered 1–70
            $imgNumber = ($i % 70) + 1;
            $url       = "https://i.pravatar.cc/200?img={$imgNumber}";

            try {
                $response = Http::timeout(10)->get($url);

                if (!$response->successful()) {
                    $this->command->warn("  Skipped participant {$participant->mrn}: HTTP {$response->status()}");
                    continue;
                }

                $dir  = "participants/{$participant->id}";
                $path = "{$dir}/photo.jpg";

                Storage::disk('public')->makeDirectory($dir);
                Storage::disk('public')->put($path, $response->body());

                $participant->update(['photo_path' => $path]);

                $this->command->line("  Photo {$imgNumber} → {$participant->mrn} ({$participant->first_name} {$participant->last_name})");

            } catch (\Exception $e) {
                $this->command->warn("  Skipped participant {$participant->mrn}: {$e->getMessage()}");
            }
        }

        $this->command->info('Done. Run ./start.sh if needed to restore permissions.');
    }
}
