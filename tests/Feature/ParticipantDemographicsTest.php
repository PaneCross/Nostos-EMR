<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantContact;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ─── W4-3: Participant Demographics Expansion Tests ───────────────────────────
// Tests race/ethnicity (OMB two-question format, GAP-07 / QW-03), marital status,
// legal representative FK, and collateral SDOH fields (religion, veteran, education).
//
// All demographics fields are nullable — participants may decline any question.
// 'Declined' is a valid value for race and ethnicity (not a missing value).
// The legal_representative_contact_id FK must reference a contact for THIS participant.
// ─────────────────────────────────────────────────────────────────────────────

class ParticipantDemographicsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private Site $site;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user   = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'enrollment',
            'role'       => 'admin',
        ]);
        $this->participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
    }

    // ── Part A: Race & Ethnicity ──────────────────────────────────────────────

    public function test_race_and_ethnicity_can_be_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'race'      => 'black_african_american',
                'ethnicity' => 'not_hispanic_latino',
            ])
            ->assertRedirect();

        $this->participant->refresh();
        $this->assertEquals('black_african_american', $this->participant->race);
        $this->assertEquals('not_hispanic_latino', $this->participant->ethnicity);
    }

    public function test_declined_is_valid_for_race(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'race' => 'declined',
            ])
            ->assertRedirect();

        $this->assertEquals('declined', $this->participant->fresh()->race);
    }

    public function test_declined_is_valid_for_ethnicity(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'ethnicity' => 'declined',
            ])
            ->assertRedirect();

        $this->assertEquals('declined', $this->participant->fresh()->ethnicity);
    }

    public function test_race_detail_free_text_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'race'        => 'other',
                'race_detail' => 'Guatemalan',
            ])
            ->assertRedirect();

        $this->participant->refresh();
        $this->assertEquals('other', $this->participant->race);
        $this->assertEquals('Guatemalan', $this->participant->race_detail);
    }

    public function test_invalid_race_value_rejected(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'race' => 'martian',
            ])
            ->assertSessionHasErrors(['race']);
    }

    public function test_all_ethnicity_values_accepted(): void
    {
        $valid = ['hispanic_latino', 'not_hispanic_latino', 'unknown', 'declined'];

        foreach ($valid as $value) {
            $this->actingAs($this->user)
                ->patch("/participants/{$this->participant->id}", [
                    'ethnicity' => $value,
                ])
                ->assertRedirect();
        }
    }

    public function test_race_fields_nullable(): void
    {
        $this->participant->update(['race' => 'white', 'ethnicity' => 'not_hispanic_latino']);

        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'race'      => null,
                'ethnicity' => null,
            ])
            ->assertRedirect();

        $this->participant->refresh();
        $this->assertNull($this->participant->race);
        $this->assertNull($this->participant->ethnicity);
    }

    // ── Part B: Marital Status & Legal Representative ─────────────────────────

    public function test_marital_status_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'marital_status' => 'widowed',
            ])
            ->assertRedirect();

        $this->assertEquals('widowed', $this->participant->fresh()->marital_status);
    }

    public function test_invalid_marital_status_rejected(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'marital_status' => 'complicated',
            ])
            ->assertSessionHasErrors(['marital_status']);
    }

    public function test_legal_representative_contact_id_must_be_valid_contact(): void
    {
        // Contact from a DIFFERENT participant — FK should fail validation
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
        $otherContact = ParticipantContact::factory()->create([
            'participant_id' => $otherParticipant->id,
        ]);

        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'legal_representative_contact_id' => 999999,
            ])
            ->assertSessionHasErrors(['legal_representative_contact_id']);
    }

    public function test_legal_representative_contact_id_linked_to_own_contact(): void
    {
        $contact = ParticipantContact::factory()->create([
            'participant_id' => $this->participant->id,
        ]);

        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'legal_representative_type'       => 'durable_poa',
                'legal_representative_contact_id' => $contact->id,
            ])
            ->assertRedirect();

        $this->participant->refresh();
        $this->assertEquals('durable_poa', $this->participant->legal_representative_type);
        $this->assertEquals($contact->id, $this->participant->legal_representative_contact_id);
    }

    // ── Part C: Additional SDOH Fields ───────────────────────────────────────

    public function test_veteran_status_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'veteran_status' => 'veteran_active',
            ])
            ->assertRedirect();

        $this->assertEquals('veteran_active', $this->participant->fresh()->veteran_status);
    }

    public function test_education_level_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'education_level' => 'high_school_ged',
            ])
            ->assertRedirect();

        $this->assertEquals('high_school_ged', $this->participant->fresh()->education_level);
    }

    public function test_religion_free_text_saved(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'religion' => 'Catholic',
            ])
            ->assertRedirect();

        $this->assertEquals('Catholic', $this->participant->fresh()->religion);
    }

    public function test_invalid_veteran_status_rejected(): void
    {
        $this->actingAs($this->user)
            ->patch("/participants/{$this->participant->id}", [
                'veteran_status' => 'army',
            ])
            ->assertSessionHasErrors(['veteran_status']);
    }

    // ── Show page includes demographics in Inertia props ─────────────────────

    public function test_show_page_returns_demographics_fields(): void
    {
        $this->participant->update([
            'race'           => 'asian',
            'ethnicity'      => 'not_hispanic_latino',
            'marital_status' => 'married',
            'veteran_status' => 'not_veteran',
        ]);

        $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Participants/Show')
                ->has('participant.race')
                ->has('participant.ethnicity')
                ->has('participant.marital_status')
                ->has('participant.veteran_status')
                ->where('participant.race', 'asian')
                ->where('participant.ethnicity', 'not_hispanic_latino')
                ->where('participant.marital_status', 'married')
            );
    }

    public function test_participant_factory_includes_demographics(): void
    {
        $p = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);

        // Factory should set non-null demographics for most participants
        $this->assertNotNull($p->race);
        $this->assertNotNull($p->ethnicity);
        $this->assertNotNull($p->marital_status);
        $this->assertNotNull($p->veteran_status);
        $this->assertNotNull($p->education_level);
    }

    public function test_all_race_enum_values_accepted_by_db(): void
    {
        $values = [
            'white', 'black_african_american', 'asian', 'american_indian_alaska_native',
            'native_hawaiian_pacific_islander', 'multiracial', 'other', 'unknown', 'declined',
        ];

        foreach ($values as $value) {
            $this->participant->update(['race' => $value]);
            $this->assertEquals($value, $this->participant->fresh()->race);
        }
    }
}
