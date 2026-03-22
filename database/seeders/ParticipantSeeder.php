<?php

namespace Database\Seeders;

use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\ParticipantAddress;
use App\Models\ParticipantContact;
use App\Models\ParticipantFlag;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ParticipantSeeder extends Seeder
{
    private const ALL_FLAG_TYPES = [
        'wheelchair', 'stretcher', 'oxygen', 'behavioral',
        'fall_risk', 'wandering_risk', 'isolation', 'dnr',
        'weight_bearing_restriction', 'dietary_restriction',
        'elopement_risk', 'hospice', 'other',
    ];

    private const RELATIONSHIPS = ['Daughter', 'Son', 'Spouse', 'Sibling', 'Friend', 'Nephew', 'Niece', 'Grandson', 'Granddaughter'];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $eastSite = Site::where('tenant_id', $tenant->id)
            ->where('name', 'Sunrise PACE East')
            ->firstOrFail();

        $westSite = Site::where('tenant_id', $tenant->id)
            ->where('name', 'Sunrise PACE West')
            ->firstOrFail();

        $enrollmentUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'enrollment')
            ->first();

        $sites = [$eastSite, $westSite];

        $this->command->info('  Creating 30 demo participants...');

        // ─── 25 Enrolled ──────────────────────────────────────────────────────
        for ($i = 0; $i < 25; $i++) {
            $site = $sites[$i % 2];
            $participant = Participant::factory()
                ->enrolled()
                ->forTenant($tenant->id)
                ->forSite($site->id)
                ->create(['created_by_user_id' => $enrollmentUser?->id]);

            $this->seedAddress($participant);
            $this->seedContact($participant);
            $this->seedInsurance($participant, true);
            $this->seedFlags($participant, $tenant->id, rand(1, 3));
        }

        // ─── 3 Disenrolled ────────────────────────────────────────────────────
        for ($i = 0; $i < 3; $i++) {
            $site = $sites[$i % 2];
            $participant = Participant::factory()
                ->disenrolled()
                ->forTenant($tenant->id)
                ->forSite($site->id)
                ->create(['created_by_user_id' => $enrollmentUser?->id]);

            $this->seedAddress($participant);
            $this->seedContact($participant);
            $this->seedInsurance($participant, false);
        }

        // ─── 2 Deceased ───────────────────────────────────────────────────────
        for ($i = 0; $i < 2; $i++) {
            $site = $sites[$i % 2];
            $participant = Participant::factory()
                ->deceased()
                ->forTenant($tenant->id)
                ->forSite($site->id)
                ->create(['created_by_user_id' => $enrollmentUser?->id]);

            $this->seedAddress($participant);
            $this->seedContact($participant);
            $this->seedInsurance($participant, false);
        }

        $this->command->line("  <comment>30 participants seeded</comment> (25 enrolled · 3 disenrolled · 2 deceased)");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function seedAddress(Participant $participant): void
    {
        $cities = ['Long Beach', 'Inglewood', 'Compton', 'Carson', 'Torrance', 'Hawthorne'];

        ParticipantAddress::create([
            'participant_id' => $participant->id,
            'address_type'   => 'home',
            'street'         => fake()->buildingNumber() . ' ' . fake()->streetName(),
            'unit'           => fake()->boolean(20) ? 'Apt ' . rand(1, 99) : null,
            'city'           => fake()->randomElement($cities),
            'state'          => 'CA',
            'zip'            => fake()->numerify('902##'),
            'is_primary'     => true,
        ]);
    }

    private function seedContact(Participant $participant): void
    {
        $firstNames = ['Maria', 'Carlos', 'Jennifer', 'David', 'Sandra', 'Michael', 'Lisa', 'James'];
        $lastNames  = ['Garcia', 'Hernandez', 'Johnson', 'Williams', 'Brown', 'Jones', 'Davis'];

        ParticipantContact::create([
            'participant_id'       => $participant->id,
            'contact_type'         => 'emergency',
            'first_name'           => fake()->randomElement($firstNames),
            'last_name'            => fake()->randomElement($lastNames),
            'relationship'         => fake()->randomElement(self::RELATIONSHIPS),
            'phone_primary'        => fake()->numerify('(###) ###-####'),
            'phone_secondary'      => null,
            'email'                => null,
            'is_emergency_contact' => true,
            'is_legal_representative' => false,
            'notes'                => null,
        ]);
    }

    private function seedInsurance(Participant $participant, bool $enrolled): void
    {
        $termDate = $enrolled ? null : $participant->disenrollment_date;

        // All PACE participants have Medicare A + B
        foreach (['medicare_a', 'medicare_b'] as $payerType) {
            InsuranceCoverage::create([
                'participant_id' => $participant->id,
                'payer_type'     => $payerType,
                'plan_name'      => 'Medicare',
                'member_id'      => $participant->medicare_id,
                'effective_date' => $participant->enrollment_date,
                'term_date'      => $termDate,
                'is_active'      => $enrolled,
            ]);
        }

        // Most also have Medicaid
        if (fake()->boolean(85)) {
            InsuranceCoverage::create([
                'participant_id' => $participant->id,
                'payer_type'     => 'medicaid',
                'plan_name'      => 'Medi-Cal',
                'member_id'      => $participant->medicaid_id,
                'effective_date' => $participant->enrollment_date,
                'term_date'      => $termDate,
                'is_active'      => $enrolled,
            ]);
        }
    }

    private function seedFlags(Participant $participant, int $tenantId, int $count): void
    {
        $flagTypes = fake()->randomElements(self::ALL_FLAG_TYPES, $count);

        foreach ($flagTypes as $flagType) {
            ParticipantFlag::create([
                'participant_id'     => $participant->id,
                'tenant_id'          => $tenantId,
                'flag_type'          => $flagType,
                'description'        => fake()->boolean(60) ? fake()->sentence(8) : null,
                'severity'           => fake()->randomElement(['low', 'medium', 'high', 'critical']),
                'is_active'          => true,
                'created_by_user_id' => null,
            ]);
        }
    }
}
