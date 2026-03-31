<?php

// ─── FacesheetTest ─────────────────────────────────────────────────────────────
// Verifies that the participant profile page loads correctly (the facesheet/
// overview is rendered server-side via Inertia, not a separate PDF endpoint).
// Tests confirm the page responds 200 and that the Inertia props contain
// the required participant clinical data fields.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacesheetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site   $site;
    private User   $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user   = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
        ]);
    }

    /** Participant profile page returns 200 for authenticated user. */
    public function test_participant_profile_returns_200(): void
    {
        $participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$participant->id}");

        $response->assertOk();
    }

    /** Response is an Inertia page with participant data in props. */
    public function test_participant_profile_contains_participant_data(): void
    {
        $participant = Participant::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'first_name' => 'Gertrude',
            'last_name'  => 'Testpatient',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Participants/Show')
                ->where('participant.first_name', 'Gertrude')
                ->where('participant.last_name', 'Testpatient')
            );
    }

    /** Allergies prop is present (may be empty array — 'NKDA' implied when empty). */
    public function test_participant_profile_includes_allergies(): void
    {
        $participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);

        Allergy::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $participant->id,
            'allergy_type'   => 'drug',
            'allergen_name'  => 'Penicillin',
            'severity'       => 'life_threatening',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Participants/Show')
                ->has('allergies')
            );
    }

    /** Problems prop is present in Inertia page props. */
    public function test_participant_profile_includes_problems(): void
    {
        $participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);

        Problem::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $participant->id,
            'status'         => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Participants/Show')
                ->has('problems')
            );
    }

    /** Advance directive fields are included in participant prop. */
    public function test_participant_profile_includes_advance_directive_fields(): void
    {
        $participant = Participant::factory()->create([
            'tenant_id'                  => $this->tenant->id,
            'site_id'                    => $this->site->id,
            'advance_directive_status'   => 'has_directive',
            'advance_directive_type'     => 'dnr',
            'advance_directive_reviewed_at' => now()->subMonth(),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Participants/Show')
                ->where('participant.advance_directive_status', 'has_directive')
                ->where('participant.advance_directive_type', 'dnr')
            );
    }

    /** Cross-tenant participant returns 403 (authorizeForTenant uses abort_if(..., 403)). */
    public function test_cross_tenant_participant_returns_403(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$otherParticipant->id}");

        $response->assertForbidden();
    }
}
