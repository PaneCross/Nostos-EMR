<?php

// ─── BreakGlassServiceTest ────────────────────────────────────────────────────
// Unit tests for BreakGlassService (W5-1).
// HIPAA 45 CFR §164.312(a)(2)(ii) — emergency access override monitoring.
//
// Coverage:
//   - requestAccess(): creates event with 4-hour TTL
//   - requestAccess(): short justification throws ValidationException
//   - requestAccess(): rate limit (3/24h) throws ValidationException on 4th
//   - requestAccess(): creates critical alert for it_admin + qa_compliance
//   - requestAccess(): writes to immutable audit log
//   - hasActiveAccess(): returns true when active event exists
//   - hasActiveAccess(): returns false when event is expired
//   - hasActiveAccess(): returns false when no event exists
//   - acknowledge(): sets acknowledged_at and supervisor FK
// ─────────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use App\Services\BreakGlassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BreakGlassServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'primary_care'): User
    {
        return User::factory()->create(['department' => $dept]);
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
        return 'Emergency access required for clinical review — patient unresponsive.';
    }

    // ── requestAccess() ───────────────────────────────────────────────────────

    /** @test */
    public function test_request_access_creates_event_with_4_hour_ttl(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $service = app(BreakGlassService::class);
        $event   = $service->requestAccess($user, $participant, $this->validJustification());

        $this->assertInstanceOf(BreakGlassEvent::class, $event);
        $this->assertEquals($user->id, $event->user_id);
        $this->assertEquals($participant->id, $event->participant_id);

        // TTL should be exactly ACCESS_DURATION_HOURS from granted
        $hoursUntilExpiry = abs((int) $event->access_granted_at->diffInHours($event->access_expires_at));
        $this->assertEquals(BreakGlassEvent::ACCESS_DURATION_HOURS, $hoursUntilExpiry);
    }

    /** @test */
    public function test_request_access_stores_justification_text(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $justification = $this->validJustification();

        $service = app(BreakGlassService::class);
        $event   = $service->requestAccess($user, $participant, $justification);

        $this->assertEquals($justification, $event->justification);
    }

    /** @test */
    public function test_short_justification_throws_validation_exception(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $service = app(BreakGlassService::class);

        $this->expectException(ValidationException::class);
        $service->requestAccess($user, $participant, 'Too short'); // <20 chars
    }

    /** @test */
    public function test_rate_limit_throws_validation_exception_on_fourth_request(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Seed 3 BTG events (at the limit)
        BreakGlassEvent::factory()->count(BreakGlassEvent::RATE_LIMIT_PER_DAY)->create([
            'user_id'           => $user->id,
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'access_granted_at' => now()->subHour(),
            'access_expires_at' => now()->addHours(3),
        ]);

        $service = app(BreakGlassService::class);

        $this->expectException(ValidationException::class);
        $service->requestAccess($user, $participant, $this->validJustification());
    }

    /** @test */
    public function test_request_access_creates_critical_alert_for_it_admin_and_qa(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $service = app(BreakGlassService::class);
        $service->requestAccess($user, $participant, $this->validJustification());

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $user->tenant_id,
            'alert_type' => 'break_glass_access',
            'severity'   => 'critical',
        ]);
    }

    /** @test */
    public function test_request_access_writes_to_audit_log(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $service = app(BreakGlassService::class);
        $service->requestAccess($user, $participant, $this->validJustification());

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'break_glass_access',
            'tenant_id'     => $user->tenant_id,
            'user_id'       => $user->id,
            'resource_type' => 'participant',
            'resource_id'   => $participant->id,
        ]);
    }

    // ── hasActiveAccess() ─────────────────────────────────────────────────────

    /** @test */
    public function test_has_active_access_returns_true_for_active_event(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        BreakGlassEvent::factory()->active()->create([
            'user_id'        => $user->id,
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $service = app(BreakGlassService::class);
        $this->assertTrue($service->hasActiveAccess($user, $participant));
    }

    /** @test */
    public function test_has_active_access_returns_false_for_expired_event(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        BreakGlassEvent::factory()->expired()->create([
            'user_id'        => $user->id,
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $service = app(BreakGlassService::class);
        $this->assertFalse($service->hasActiveAccess($user, $participant));
    }

    /** @test */
    public function test_has_active_access_returns_false_when_no_event_exists(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $service = app(BreakGlassService::class);
        $this->assertFalse($service->hasActiveAccess($user, $participant));
    }

    // ── acknowledge() ─────────────────────────────────────────────────────────

    /** @test */
    public function test_acknowledge_sets_acknowledged_at_and_supervisor(): void
    {
        $clinician  = $this->makeUser();
        $supervisor = $this->makeUser('it_admin');
        $participant = $this->makeParticipant($clinician);

        $event = BreakGlassEvent::factory()->expired()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $clinician->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $service = app(BreakGlassService::class);
        $service->acknowledge($event, $supervisor);

        $fresh = $event->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertEquals($supervisor->id, $fresh->acknowledged_by_supervisor_user_id);
    }

    /** @test */
    public function test_event_is_acknowledged_after_acknowledge_call(): void
    {
        $clinician  = $this->makeUser();
        $supervisor = $this->makeUser('it_admin');
        $participant = $this->makeParticipant($clinician);

        $event = BreakGlassEvent::factory()->expired()->create([
            'user_id'        => $clinician->id,
            'tenant_id'      => $clinician->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $this->assertFalse($event->isAcknowledged());

        $service = app(BreakGlassService::class);
        $service->acknowledge($event, $supervisor);

        $this->assertTrue($event->fresh()->isAcknowledged());
    }
}
