<?php

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllergyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'TEST',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_create_allergy_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergy_type'         => 'drug',
                'allergen_name'        => 'Penicillin',
                'reaction_description' => 'Hives / urticaria',
                'severity'             => 'moderate',
                'is_active'            => true,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_allergies', [
            'participant_id' => $this->participant->id,
            'allergen_name'  => 'Penicillin',
            'severity'       => 'moderate',
            'is_active'      => true,
        ]);
    }

    public function test_create_life_threatening_allergy(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergy_type'         => 'food',
                'allergen_name'        => 'Peanuts',
                'reaction_description' => 'Anaphylaxis',
                'severity'             => Allergy::LIFE_THREATENING,
                'is_active'            => true,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_allergies', [
            'participant_id' => $this->participant->id,
            'allergen_name'  => 'Peanuts',
            'severity'       => Allergy::LIFE_THREATENING,
        ]);
    }

    public function test_create_allergy_requires_allergy_type(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergen_name' => 'Penicillin',
                'severity'      => 'moderate',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allergy_type']);
    }

    public function test_create_allergy_requires_valid_allergy_type(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergy_type'  => 'invalid_type',
                'allergen_name' => 'Something',
                'severity'      => 'mild',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allergy_type']);
    }

    public function test_create_allergy_requires_allergen_name(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergy_type' => 'drug',
                'severity'     => 'moderate',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allergen_name']);
    }

    public function test_create_allergy_requires_valid_severity(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/allergies", [
                'allergy_type'  => 'drug',
                'allergen_name' => 'Penicillin',
                'severity'      => 'deadly',  // not in enum
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['severity']);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_active_and_inactive_allergies(): void
    {
        // Use distinct types so the grouped response always has 3 keys
        Allergy::factory()->drug()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create();
        Allergy::factory()->state(['allergy_type' => 'food', 'allergen_name' => 'Peanuts'])
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create();
        Allergy::factory()->inactive()
            ->state(['allergy_type' => 'environmental', 'allergen_name' => 'Latex'])
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/allergies");

        $response->assertOk();
        // Controller groups by allergy_type — 3 distinct types means 3 groups
        $this->assertCount(3, $response->json());
    }

    // ─── Participant profile includes life-threatening count ──────────────────

    public function test_participant_profile_includes_life_threatening_allergy_count(): void
    {
        // Add two life-threatening allergies
        Allergy::factory()->lifeThreatening()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->count(2)
            ->create();
        // Add one non-life-threatening
        Allergy::factory()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['severity' => 'mild']);

        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $resp = $this->actingAs($this->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get("/participants/{$this->participant->id}");

        $resp->assertOk();
        $props = $resp->json('props');
        $this->assertEquals(2, $props['lifeThreateningAllergyCount']);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_update_allergy_is_active_false(): void
    {
        $allergy = Allergy::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['is_active' => true]);

        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/allergies/{$allergy->id}", [
                'allergy_type'  => $allergy->allergy_type,
                'allergen_name' => $allergy->allergen_name,
                'severity'      => $allergy->severity,
                'is_active'     => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_allergies', [
            'id'        => $allergy->id,
            'is_active' => false,
        ]);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_view_allergies_from_different_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/allergies")
            ->assertForbidden();
    }
}
