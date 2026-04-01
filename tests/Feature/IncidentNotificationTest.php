<?php

// ─── IncidentNotificationTest ─────────────────────────────────────────────────
// Feature tests for W4-6 CMS/SMA incident notification tracking.
// 42 CFR §460.136: PACE must notify CMS and SMA within 72 hours of significant
// adverse events (abuse_neglect, hospitalization, er_visit, unexpected_death).
//
// Coverage:
//   - cms_notification_required auto-set by IncidentService for qualifying types
//   - regulatory_deadline = occurred_at + 72h for qualifying types
//   - cms_notification_required = false for non-qualifying incident types
//   - scopeCmsNotificationOverdue() returns overdue incidents correctly
//   - isCmsNotificationOverdue() model helper
//   - IncidentNotificationOverdueJob creates critical alert + deduplicates
//   - QA dashboard shows cms_notification_overdue_count KPI
//   - 'unexpected_death' is a valid incident type (W4-6 addition)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\IncidentNotificationOverdueJob;
use App\Models\Alert;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeQaUser(?int $tenantId = null): User
    {
        $attrs = ['department' => 'qa_compliance'];
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

    // ── CMS notification auto-set (IncidentService) ───────────────────────────

    /** @test */
    public function test_cms_notification_required_set_for_abuse_neglect(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id' => $participant->id,
            'incident_type'  => 'abuse_neglect',
            'occurred_at'    => now()->toIso8601String(),
            'description'    => 'Suspected abuse case reported.',
            'injuries_sustained' => false,
        ])->assertStatus(201);

        $incident = Incident::where('participant_id', $participant->id)->first();
        $this->assertTrue($incident->cms_notification_required);
        $this->assertNotNull($incident->regulatory_deadline);
    }

    /** @test */
    public function test_regulatory_deadline_is_72h_after_occurred_at(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);
        $occurredAt  = now()->subHours(10);

        $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id' => $participant->id,
            'incident_type'  => 'hospitalization',
            'occurred_at'    => $occurredAt->toIso8601String(),
            'description'    => 'Emergency hospitalization.',
            'injuries_sustained' => false,
        ])->assertStatus(201);

        $incident = Incident::where('participant_id', $participant->id)->first();
        $expected = $occurredAt->addHours(72)->toDateTimeString();
        $this->assertEquals(
            $expected,
            $incident->regulatory_deadline->toDateTimeString()
        );
    }

    /** @test */
    public function test_cms_notification_not_required_for_non_qualifying_types(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id' => $participant->id,
            'incident_type'  => 'fall',
            'occurred_at'    => now()->toIso8601String(),
            'description'    => 'Participant fell in day center.',
            'injuries_sustained' => false,
        ])->assertStatus(201);

        $incident = Incident::where('participant_id', $participant->id)->first();
        // Falls do not trigger CMS notification requirement
        $this->assertFalse($incident->cms_notification_required);
        $this->assertNull($incident->regulatory_deadline);
    }

    /** @test */
    public function test_unexpected_death_is_valid_incident_type(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $response = $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id' => $participant->id,
            'incident_type'  => 'unexpected_death',
            'occurred_at'    => now()->toIso8601String(),
            'description'    => 'Participant passed unexpectedly.',
            'injuries_sustained' => false,
        ]);

        $response->assertStatus(201);
        $incident = Incident::where('participant_id', $participant->id)->first();
        $this->assertEquals('unexpected_death', $incident->incident_type);
        $this->assertTrue($incident->cms_notification_required);
    }

    // ── Model helper + scope ──────────────────────────────────────────────────

    /** @test */
    public function test_cms_notification_overdue_scope_returns_correct_incidents(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        // Overdue: deadline passed, notification not sent
        $overdue = Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'abuse_neglect',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->subHours(2),
            'occurred_at'               => now()->subHours(74),
        ]);

        // Not overdue (deadline in future)
        $pending = Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'hospitalization',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->addHours(24),
            'occurred_at'               => now()->subHours(48),
        ]);

        // Already notified (should not appear)
        $notified = Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'er_visit',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => now()->subHours(5),
            'regulatory_deadline'       => now()->subHours(2),
            'occurred_at'               => now()->subHours(74),
        ]);

        $overdueResults = Incident::cmsNotificationOverdue()->pluck('id')->all();
        $this->assertContains($overdue->id, $overdueResults);
        $this->assertNotContains($pending->id, $overdueResults);
        $this->assertNotContains($notified->id, $overdueResults);
    }

    /** @test */
    public function test_incident_is_cms_notification_overdue_model_helper(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $overdueIncident = Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->subHour(),
            'occurred_at'               => now()->subHours(73),
        ]);

        $this->assertTrue($overdueIncident->isCmsNotificationOverdue());

        // Mark as notified
        $overdueIncident->update(['cms_notification_sent_at' => now()]);
        $this->assertFalse($overdueIncident->fresh()->isCmsNotificationOverdue());
    }

    // ── IncidentNotificationOverdueJob ────────────────────────────────────────

    /** @test */
    public function test_overdue_job_creates_critical_alert_for_overdue_incident(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'abuse_neglect',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->subHours(3),
            'occurred_at'               => now()->subHours(75),
            'status'                    => 'open',
        ]);

        (new IncidentNotificationOverdueJob())->handle();

        $this->assertDatabaseHas('emr_alerts', [
            'alert_type' => 'cms_notification_overdue',
            'severity'   => 'critical',
        ]);
    }

    /** @test */
    public function test_overdue_job_deduplicates_alerts(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $incident = Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'hospitalization',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->subHours(2),
            'occurred_at'               => now()->subHours(74),
            'status'                    => 'open',
        ]);

        // Run job twice
        (new IncidentNotificationOverdueJob())->handle();
        (new IncidentNotificationOverdueJob())->handle();

        // Only 1 alert should exist for this incident
        $alertCount = Alert::where('alert_type', 'cms_notification_overdue')
            ->where(fn($q) => $q->whereJsonContains('metadata->incident_id', $incident->id))
            ->count();
        $this->assertEquals(1, $alertCount);
    }

    // ── QA Dashboard KPI ──────────────────────────────────────────────────────

    /** @test */
    public function test_qa_dashboard_includes_cms_notification_kpi(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'                 => $user->tenant_id,
            'participant_id'            => $participant->id,
            'incident_type'             => 'abuse_neglect',
            'cms_notification_required' => true,
            'cms_notification_sent_at'  => null,
            'regulatory_deadline'       => now()->subHours(3),
            'occurred_at'               => now()->subHours(75),
            'status'                    => 'open',
        ]);

        $response = $this->actingAs($user)->get('/qa/dashboard');
        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->has('kpis.cms_notification_overdue_count')
                     ->where('kpis.cms_notification_overdue_count', 1)
            );
    }
}
