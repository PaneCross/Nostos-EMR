<?php

// ─── BreakGlassTest ───────────────────────────────────────────────────────────
// Feature tests for W5-1 Break-the-Glass emergency access module.
// HIPAA 45 CFR §164.312(a)(2)(ii) — emergency access override monitoring.
//
// Coverage:
//   - requestAccess: creates BTG event with correct TTL (4h)
//   - requestAccess: short justification (<20 chars) → 422
//   - requestAccess: rate limit (max 3/24h) → 422 on 4th
//   - requestAccess: cross-tenant participant → 403
//   - adminIndex: IT Admin sees event log (Inertia)
//   - adminIndex: non-IT-Admin → 403
//   - acknowledge: IT Admin marks event reviewed
//   - acknowledge: double-acknowledge → 409
//   - Dashboard BTG widget: returns correct JSON structure
// ─────────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakGlassTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeClinicalUser(?int $tenantId = null, string $dept = 'primary_care'): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeItAdmin(?int $tenantId = null): User
    {
        $attrs = ['department' => 'it_admin'];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeParticipant(User $user): Participant
    {
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);
    }

    private function validJustification(): string
    {
        return 'Emergency home visit — participant unresponsive and needs medication review.';
    }

    // ── requestAccess ─────────────────────────────────────────────────────────

    /** @test */
    public function test_user_can_request_emergency_access(): void
    {
        $user        = $this->makeClinicalUser();
        $participant = $this->makeParticipant($user);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/break-glass", [
                'justification' => $this->validJustification(),
            ])
            ->assertCreated();

        $response->assertJsonStructure(['event_id', 'access_expires_at']);

        $this->assertDatabaseHas('emr_break_glass_events', [
            'user_id'        => $user->id,
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
        ]);
    }

    /** @test */
    public function test_btg_access_expires_at_is_4_hours_from_now(): void
    {
        $user        = $this->makeClinicalUser();
        $participant = $this->makeParticipant($user);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/break-glass", [
                'justification' => $this->validJustification(),
            ])
            ->assertCreated();

        // Compare expires_at to granted_at on the stored event (avoids Carbon 3 truncation
        // that occurs when comparing to now(), which is slightly after access_granted_at).
        $event = BreakGlassEvent::where('user_id', $user->id)
            ->where('participant_id', $participant->id)
            ->latest('id')
            ->firstOrFail();

        $hoursUntilExpiry = abs((int) $event->access_granted_at->diffInHours($event->access_expires_at));

        $this->assertEquals(BreakGlassEvent::ACCESS_DURATION_HOURS, $hoursUntilExpiry);
    }

    /** @test */
    public function test_short_justification_is_rejected(): void
    {
        $user        = $this->makeClinicalUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/break-glass", [
                'justification' => 'Too short',  // <20 chars
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['justification']);
    }

    /** @test */
    public function test_rate_limit_blocks_fourth_request_within_24_hours(): void
    {
        $user        = $this->makeClinicalUser();
        $participant = $this->makeParticipant($user);

        // Seed 3 BTG events within the last 24 hours (max allowed)
        BreakGlassEvent::factory()->count(BreakGlassEvent::RATE_LIMIT_PER_DAY)->create([
            'user_id'        => $user->id,
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'access_granted_at' => now()->subHour(),
            'access_expires_at' => now()->addHours(3),
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/break-glass", [
                'justification' => $this->validJustification(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['justification']);
    }

    /** @test */
    public function test_cross_tenant_btg_request_is_blocked(): void
    {
        $user         = $this->makeClinicalUser();
        $otherTenant  = Tenant::factory()->create();
        $otherSite    = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$otherParticipant->id}/break-glass", [
                'justification' => $this->validJustification(),
            ])
            ->assertForbidden();
    }

    /** @test */
    public function test_btg_request_requires_authentication(): void
    {
        $user        = $this->makeClinicalUser();
        $participant = $this->makeParticipant($user);

        $this->postJson("/participants/{$participant->id}/break-glass", [
            'justification' => $this->validJustification(),
        ])->assertUnauthorized();
    }

    // ── adminIndex ────────────────────────────────────────────────────────────

    /** @test */
    public function test_it_admin_can_view_btg_event_log(): void
    {
        $itAdmin = $this->makeItAdmin();

        $this->actingAs($itAdmin)
            ->get('/it-admin/break-glass')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events')
                ->has('unacknowledged_count')
            );
    }

    /** @test */
    public function test_non_it_admin_cannot_view_btg_event_log(): void
    {
        $financeUser = $this->makeClinicalUser(dept: 'finance');

        $this->actingAs($financeUser)
            ->get('/it-admin/break-glass')
            ->assertForbidden();
    }

    /** @test */
    public function test_btg_admin_index_shows_only_tenant_events(): void
    {
        $itAdmin     = $this->makeItAdmin();
        $clinician   = $this->makeClinicalUser($itAdmin->tenant_id, 'primary_care');
        $participant = $this->makeParticipant($clinician);

        // This tenant's event
        BreakGlassEvent::factory()->active()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $itAdmin->tenant_id,
            'participant_id' => $participant->id,
        ]);

        // Different tenant's event (should NOT appear)
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        BreakGlassEvent::factory()->active()->create([
            'user_id'        => $otherUser->id,
            'tenant_id'      => $otherTenant->id,
            'participant_id' => $otherParticipant->id,
        ]);

        $response = $this->actingAs($itAdmin)
            ->get('/it-admin/break-glass')
            ->assertOk();

        // Only 1 event should be returned — the one in our tenant
        $this->assertCount(1, $response->viewData('page')['props']['events'] ?? []);
    }

    // ── acknowledge ───────────────────────────────────────────────────────────

    /** @test */
    public function test_it_admin_can_acknowledge_btg_event(): void
    {
        $itAdmin  = $this->makeItAdmin();
        $clinician = $this->makeClinicalUser($itAdmin->tenant_id, 'primary_care');
        $site = Site::factory()->create(['tenant_id' => $itAdmin->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $itAdmin->tenant_id,
            'site_id'   => $site->id,
        ]);

        $event = BreakGlassEvent::factory()->expired()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $itAdmin->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $this->actingAs($itAdmin)
            ->postJson("/it-admin/break-glass/{$event->id}/acknowledge")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Acknowledged.']);

        $this->assertNotNull($event->fresh()->acknowledged_at);
        $this->assertEquals($itAdmin->id, $event->fresh()->acknowledged_by_supervisor_user_id);
    }

    /** @test */
    public function test_double_acknowledging_event_returns_409(): void
    {
        $itAdmin  = $this->makeItAdmin();
        $site = Site::factory()->create(['tenant_id' => $itAdmin->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $itAdmin->tenant_id,
            'site_id'   => $site->id,
        ]);
        $clinician = User::factory()->create(['tenant_id' => $itAdmin->tenant_id]);

        $event = BreakGlassEvent::factory()->acknowledged()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $itAdmin->tenant_id,
            'participant_id' => $participant->id,
            'acknowledged_by_supervisor_user_id' => $itAdmin->id,
        ]);

        $this->actingAs($itAdmin)
            ->postJson("/it-admin/break-glass/{$event->id}/acknowledge")
            ->assertStatus(409);
    }

    /** @test */
    public function test_non_it_admin_cannot_acknowledge_btg_event(): void
    {
        $clinician  = $this->makeClinicalUser(dept: 'social_work');
        $itAdmin    = $this->makeItAdmin($clinician->tenant_id);
        $site = Site::factory()->create(['tenant_id' => $clinician->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $clinician->tenant_id,
            'site_id'   => $site->id,
        ]);

        $event = BreakGlassEvent::factory()->expired()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $clinician->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $this->actingAs($clinician)
            ->postJson("/it-admin/break-glass/{$event->id}/acknowledge")
            ->assertForbidden();
    }

    // ── Dashboard BTG widget ──────────────────────────────────────────────────

    /** @test */
    public function test_it_admin_dashboard_btg_widget_returns_correct_structure(): void
    {
        $itAdmin = $this->makeItAdmin();

        $this->actingAs($itAdmin)
            ->getJson('/dashboards/it-admin/break-glass')
            ->assertOk()
            ->assertJsonStructure(['events', 'unreviewed_count', 'total_today']);
    }

    /** @test */
    public function test_it_admin_dashboard_btg_widget_counts_unreviewed(): void
    {
        $itAdmin  = $this->makeItAdmin();
        $site = Site::factory()->create(['tenant_id' => $itAdmin->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $itAdmin->tenant_id,
            'site_id'   => $site->id,
        ]);
        $clinician = User::factory()->create(['tenant_id' => $itAdmin->tenant_id]);

        // 2 unreviewed
        BreakGlassEvent::factory()->count(2)->expired()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $itAdmin->tenant_id,
            'participant_id' => $participant->id,
        ]);

        // 1 acknowledged
        BreakGlassEvent::factory()->acknowledged()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $itAdmin->tenant_id,
            'participant_id' => $participant->id,
            'acknowledged_by_supervisor_user_id' => $itAdmin->id,
        ]);

        $response = $this->actingAs($itAdmin)
            ->getJson('/dashboards/it-admin/break-glass')
            ->assertOk();

        $this->assertEquals(2, $response->json('unreviewed_count'));
    }
}
