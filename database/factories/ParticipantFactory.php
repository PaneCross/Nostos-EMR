<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    // PACE-appropriate first names (ages 65–95)
    private const FIRST_NAMES_F = [
        'Eleanor', 'Margaret', 'Dorothy', 'Helen', 'Ruth', 'Gloria', 'Shirley',
        'Norma', 'Barbara', 'Patricia', 'Betty', 'Virginia', 'Rose', 'Alice',
        'Louise', 'Frances', 'Mildred', 'Evelyn', 'Lillian', 'Martha',
    ];

    private const FIRST_NAMES_M = [
        'Robert', 'James', 'William', 'George', 'Harold', 'Frank', 'Raymond',
        'Walter', 'Charles', 'Joseph', 'Richard', 'Donald', 'Edward', 'Thomas',
        'Arthur', 'Eugene', 'Leonard', 'Howard', 'Ralph', 'Carl',
    ];

    private const LANGUAGES = ['English', 'Spanish', 'Korean', 'Mandarin', 'Tagalog', 'Armenian', 'Vietnamese', 'Russian'];

    private const GENDERS = ['female', 'male', 'non_binary', 'prefer_not_to_say'];

    /**
     * Generate a fake Medicare ID in CMS format: [A-Z]1[A-Z][0-9]{9}[A-Z]
     * Real format: 1 alpha + 10 alphanumeric chars, but we use a simplified readable fake.
     */
    private function fakeMedicareId(): string
    {
        $alpha = 'ABCDEFGHJKMNPQRSTVWXY'; // CMS-approved chars (no I, L, O, U, Z)
        $prefix  = $alpha[$this->faker->numberBetween(0, strlen($alpha) - 1)];
        $segment = $this->faker->numerify('#EG4-TE5-');
        $suffix  = strtoupper($this->faker->lexify('??'));
        return $prefix . $segment . $suffix;
    }

    public function definition(): array
    {
        $gender     = $this->faker->randomElement(self::GENDERS);
        $firstName  = in_array($gender, ['female', 'non_binary', 'prefer_not_to_say'])
            ? $this->faker->randomElement(self::FIRST_NAMES_F)
            : $this->faker->randomElement(self::FIRST_NAMES_M);

        // PACE participants are 65–95 years old
        $dob = $this->faker->dateTimeBetween('-95 years', '-65 years');

        $language         = $this->faker->randomElement(self::LANGUAGES);
        $interpreterNeeded = $language !== 'English';

        return [
            'tenant_id'               => Tenant::factory(),
            'site_id'                 => Site::factory(),
            'first_name'              => $firstName,
            'last_name'               => 'Testpatient',
            'preferred_name'          => $this->faker->boolean(25) ? $this->faker->firstName() : null,
            'dob'                     => $dob->format('Y-m-d'),
            'gender'                  => $gender,
            'pronouns'                => null,
            'ssn_last_four'           => $this->faker->numerify('####'),
            'medicare_id'             => $this->fakeMedicareId(),
            'medicaid_id'             => $this->faker->numerify('##########'),
            'pace_contract_id'        => 'H' . $this->faker->numerify('####'),
            'h_number'                => 'H' . $this->faker->numerify('####'),
            'primary_language'        => $language,
            'interpreter_needed'      => $interpreterNeeded,
            'interpreter_language'    => $interpreterNeeded ? $language : null,
            'enrollment_status'       => 'enrolled',
            'enrollment_date'         => $this->faker->dateTimeBetween('-5 years', '-3 months')->format('Y-m-d'),
            'disenrollment_date'      => null,
            'disenrollment_reason'    => null,
            'nursing_facility_eligible' => $this->faker->boolean(70),
            'nf_certification_date'   => $this->faker->boolean(60)
                ? $this->faker->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d')
                : null,
            'photo_path'              => null,
            'is_active'               => true,
            'created_by_user_id'      => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    public function enrolled(): static
    {
        return $this->state([
            'enrollment_status'    => 'enrolled',
            'disenrollment_date'   => null,
            'disenrollment_reason' => null,
            'is_active'            => true,
        ]);
    }

    public function disenrolled(): static
    {
        return $this->state(fn () => [
            'enrollment_status'    => 'disenrolled',
            'disenrollment_date'   => $this->faker->dateTimeBetween('-2 years', '-1 month')->format('Y-m-d'),
            'disenrollment_reason' => $this->faker->randomElement([
                'Moved out of service area',
                'Transferred to SNF',
                'Requested voluntary disenrollment',
                'No longer meets PACE eligibility',
            ]),
            'is_active'            => false,
        ]);
    }

    public function deceased(): static
    {
        return $this->state(fn () => [
            'enrollment_status'    => 'deceased',
            'disenrollment_date'   => $this->faker->dateTimeBetween('-1 year', '-1 month')->format('Y-m-d'),
            'disenrollment_reason' => 'Participant deceased',
            'is_active'            => false,
        ]);
    }

    public function forTenant(int $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forSite(int $siteId): static
    {
        return $this->state(['site_id' => $siteId]);
    }
}
