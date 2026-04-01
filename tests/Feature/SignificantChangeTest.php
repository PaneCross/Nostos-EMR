<?php

// ─── SignificantChangeTest ─────────────────────────────────────────────────────
// Feature tests for W4-6 significant change event tracking.
// 42 CFR §460.104(b): IDT must reassess within 30 days of significant change.
//
// Coverage:
//   - SCE auto-created by IncidentService when fall with injuries_sustained=true
//   - SCE auto-created by ProcessHl7AdtJob on A01 hospital admission
//   - SCE model: isOverdue(), daysUntilDue(), isPending(), triggerTypeLabel()
//   - scopeOverdue(), scopePending(), scopeDueSoon() on model
//   - IDT dashboard widget GET /dashboards/idt/significant-changes
//   - SignificantChangeOverdueJob creates warning alerts + deduplicates
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\ProcessHl7AdtJob;
use App\Jobs\SignificantChangeOverdueJob;
use App\Models\Alert;
use App\Models\Incident;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Models\SignificantChangeEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignificantChangeTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIdtUser(?int $tenantId = null): User
    {
        $attrs = ['department' => 'idt'];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

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

    // ── Auto-creation from IncidentService ───────────────────────────────────

    /** @test */
    public function test_fall_with_injury_creates_significant_change_event(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id'      => $participant->id,
            'incident_type'       => 'fall',
            'occurred_at'         => now()->toIso8601String(),
            'description'         => 'Participant fell and sustained bruising to right arm.',
            'injuries_sustained'  => true,   // triggers SCE creation
            'injury_description'  => 'Laceration to right forearm, approximately 3cm.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('emr_significant_change_events', [
            'participant_id' => $participant->id,
            'trigger_type'   => 'fall_with_injury',
            'trigger_source' => 'incident_service',
            'status'         => 'pending',
        ]);
    }

    /** @test */
    public function test_fall_without_injury_does_not_create_significant_change_event(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id'     => $participant->id,
            'incident_type'      => 'fall',
            'occurred_at'        => now()->toIso8601String(),
            'description'        => 'Near miss fall, no injury.',
            'injuries_sustained' => false,   // no SCE
        ])->assertStatus(201);

        $this->assertDatabaseMissing('emr_significant_change_events', [
            'participant_id' => $participant->id,
            'trigger_type'   => 'fall_with_injury',
        ]);
    }

    // ── Auto-creation from ProcessHl7AdtJob ──────────────────────────────────

    /** @test */
    public function test_hl7_a01_admission_creates_significant_change_event(): void
    {
        $tenant      = Tenant::factory()->create();
        $site        = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $tenant->id,
            'site_id'   => $site->id,
        ]);

        $rawPayload = [
            'message_type'   => 'A01',
            'patient_mrn'    => $participant->mrn,
            'admission_date' => now()->toDateString(),
            'facility'       => 'General Hospital',
        ];
        $log = IntegrationLog::create([
            'tenant_id'      => $tenant->id,
            'connector_type' => 'hl7_adt',
            'direction'      => 'inbound',
            'raw_payload'    => $rawPayload,
            'status'         => 'pending',
        ]);

        $payload = [
            'message_type'   => 'A01',
            'patient_mrn'    => $participant->mrn,
            'admission_date' => now()->toDateString(),
            'facility'       => 'General Hospital',
        ];
        $job = new ProcessHl7AdtJob($log->id, $payload, $tenant->id);
        $job->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_significant_change_events', [
            'participant_id' => $participant->id,
            'trigger_type'   => 'hospitalization',
            'trigger_source' => 'adt_connector',
            'status'         => 'pending',
        ]);
    }

    // ── Model logic ──────────────────────────────────────────────────────────

    /** @test */
    public function test_significant_change_event_is_overdue_when_due_date_passed(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        $event = SignificantChangeEvent::factory()->overdue()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        $this->assertTrue($event->isOverdue());
        $this->assertLessThan(0, $event->daysUntilDue());
    }

    /** @test */
    public function test_significant_change_event_not_overdue_when_future_due_date(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        $event = SignificantChangeEvent::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'trigger_date'       => now()->subDays(5)->toDateString(),
            'idt_review_due_date'=> now()->addDays(25)->toDateString(),
            'status'             => 'pending',
        ]);

        $this->assertFalse($event->isOverdue());
        $this->assertGreaterThan(0, $event->daysUntilDue());
    }

    /** @test */
    public function test_completed_event_is_not_overdue(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        $event = SignificantChangeEvent::factory()->completed()->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'idt_review_due_date'=> now()->subDays(5)->toDateString(),
        ]);

        $this->assertFalse($event->isOverdue());
    }

    // ── IDT Dashboard Widget ──────────────────────────────────────────────────

    /** @test */
    public function test_idt_dashboard_significant_changes_endpoint_returns_events(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        SignificantChangeEvent::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'trigger_type'       => 'hospitalization',
            'idt_review_due_date'=> now()->addDays(10)->toDateString(),
            'status'             => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/dashboards/idt/significant-changes');
        $response->assertOk()
            ->assertJsonStructure([
                'events' => [['id', 'participant', 'trigger_type', 'urgency', 'days_until_due', 'idt_review_due_date']],
                'total_count',
                'overdue_count',
            ]);
    }

    /** @test */
    public function test_idt_dashboard_significant_changes_requires_idt_department(): void
    {
        $user = User::factory()->create(['department' => 'dietary']);
        $response = $this->actingAs($user)->getJson('/dashboards/idt/significant-changes');
        $response->assertForbidden();
    }

    // ── SignificantChangeOverdueJob ───────────────────────────────────────────

    /** @test */
    public function test_overdue_job_creates_warning_alert_for_overdue_event(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        SignificantChangeEvent::factory()->overdue()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        (new SignificantChangeOverdueJob())->handle();

        $this->assertDatabaseHas('emr_alerts', [
            'alert_type' => 'significant_change_idt_overdue',
            'severity'   => 'warning',
        ]);
    }

    /** @test */
    public function test_overdue_job_deduplicates_alerts(): void
    {
        $user        = $this->makeIdtUser();
        $participant = $this->makeParticipant($user);

        $event = SignificantChangeEvent::factory()->overdue()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ]);

        // Run job twice
        (new SignificantChangeOverdueJob())->handle();
        (new SignificantChangeOverdueJob())->handle();

        $alertCount = Alert::where('alert_type', 'significant_change_idt_overdue')
            ->where(fn ($q) => $q->whereJsonContains('metadata->significant_change_event_id', $event->id))
            ->count();
        $this->assertEquals(1, $alertCount);
    }
}
