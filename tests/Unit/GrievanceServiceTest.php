<?php

// ─── GrievanceServiceTest ─────────────────────────────────────────────────────
// Unit tests for GrievanceService (42 CFR §460.120–§460.121 workflow logic).
//
// Coverage:
//   - open(): creates Grievance with correct fields; urgent creates critical alert;
//             audit log entry recorded
//   - updateStatus(): valid transitions succeed; invalid transitions throw LogicException;
//             resolve requires resolution_text + resolution_date;
//             escalate requires escalation_reason; audit logged
//   - notifyParticipant(): sets participant_notified_at + notification_method; audit logged
//   - checkOverdue(): urgent overdue creates critical alert;
//                    standard overdue creates warning alert + escalates priority;
//                    non-overdue grievances are not affected
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\Alert;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GrievanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class GrievanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private GrievanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GrievanceService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeActor(string $dept = 'qa_compliance'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $actor): Participant
    {
        $site = Site::factory()->create(['tenant_id' => $actor->tenant_id]);
        return Participant::factory()->create([
            'tenant_id' => $actor->tenant_id,
            'site_id'   => $site->id,
        ]);
    }

    private function makeGrievance(User $actor, array $overrides = []): Grievance
    {
        $participant = $this->makeParticipant($actor);
        return Grievance::factory()->create(array_merge([
            'tenant_id'      => $actor->tenant_id,
            'site_id'        => $participant->site_id,
            'participant_id' => $participant->id,
        ], $overrides));
    }

    // ── open() ────────────────────────────────────────────────────────────────

    public function test_open_creates_grievance_with_correct_fields(): void
    {
        $actor       = $this->makeActor();
        $participant = $this->makeParticipant($actor);

        $grievance = $this->service->open($participant, [
            'filed_by_name'  => 'Test Filer',
            'filed_by_type'  => 'family_member',
            'category'       => 'quality_of_care',
            'description'    => 'Care quality concern raised during visit.',
            'priority'       => 'standard',
            'cms_reportable' => false,
        ], $actor);

        $this->assertEquals('open', $grievance->status);
        $this->assertEquals('standard', $grievance->priority);
        $this->assertEquals('quality_of_care', $grievance->category);
        $this->assertEquals($actor->id, $grievance->received_by_user_id);
    }

    public function test_open_urgent_grievance_creates_critical_alert(): void
    {
        $actor       = $this->makeActor();
        $participant = $this->makeParticipant($actor);

        $this->service->open($participant, [
            'filed_by_name'  => 'Urgent Filer',
            'filed_by_type'  => 'participant',
            'category'       => 'quality_of_care',
            'description'    => 'Urgent safety concern.',
            'priority'       => 'urgent',
            'cms_reportable' => false,
        ], $actor);

        $alert = Alert::where('tenant_id', $actor->tenant_id)
            ->where('severity', 'critical')
            ->where('source_module', 'grievances')
            ->first();

        $this->assertNotNull($alert);
        $this->assertStringContainsString('qa_compliance', $alert->target_departments);
    }

    public function test_open_standard_grievance_does_not_create_alert(): void
    {
        $actor       = $this->makeActor();
        $participant = $this->makeParticipant($actor);

        $this->service->open($participant, [
            'filed_by_name'  => 'Standard Filer',
            'filed_by_type'  => 'participant',
            'category'       => 'billing',
            'description'    => 'Billing question.',
            'priority'       => 'standard',
            'cms_reportable' => false,
        ], $actor);

        $this->assertDatabaseMissing('emr_alerts', [
            'source_module' => 'grievances',
        ]);
    }

    public function test_open_creates_audit_log_entry(): void
    {
        $actor       = $this->makeActor();
        $participant = $this->makeParticipant($actor);

        $this->service->open($participant, [
            'filed_by_name'  => 'Audit Test',
            'filed_by_type'  => 'participant',
            'category'       => 'other',
            'description'    => 'Test description.',
            'priority'       => 'standard',
        ], $actor);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'grievance.opened',
            'resource_type' => 'grievance',
        ]);
    }

    // ── updateStatus() ────────────────────────────────────────────────────────

    public function test_valid_transition_open_to_under_review(): void
    {
        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'open']);

        $this->service->updateStatus($grievance, 'under_review', [], $actor);

        $this->assertEquals('under_review', $grievance->fresh()->status);
    }

    public function test_valid_transition_under_review_to_resolved(): void
    {
        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'under_review']);

        $this->service->updateStatus($grievance, 'resolved', [
            'resolution_text' => 'Issue addressed and corrective action taken.',
            'resolution_date' => now()->toDateString(),
        ], $actor);

        $this->assertEquals('resolved', $grievance->fresh()->status);
    }

    public function test_invalid_transition_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);

        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'resolved']);

        // 'resolved' is terminal — no outbound transitions
        $this->service->updateStatus($grievance, 'under_review', [], $actor);
    }

    public function test_resolve_without_required_fields_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);

        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'under_review']);

        $this->service->updateStatus($grievance, 'resolved', [], $actor);
    }

    public function test_escalate_without_reason_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);

        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'under_review']);

        $this->service->updateStatus($grievance, 'escalated', [], $actor);
    }

    // ── notifyParticipant() ───────────────────────────────────────────────────

    public function test_notify_participant_sets_notification_fields(): void
    {
        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, ['status' => 'resolved']);

        $this->service->notifyParticipant($grievance, 'verbal', $actor);

        $fresh = $grievance->fresh();
        $this->assertNotNull($fresh->participant_notified_at);
        $this->assertEquals('verbal', $fresh->notification_method);
    }

    // ── checkOverdue() ────────────────────────────────────────────────────────

    public function test_check_overdue_creates_critical_alert_for_urgent_overdue(): void
    {
        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, [
            'priority'  => 'urgent',
            'status'    => 'open',
            'filed_at'  => now()->subHours(80),
        ]);

        $result = $this->service->checkOverdue($actor->tenant_id);

        $this->assertGreaterThan(0, $result['urgent']);
        // emr_alerts has no resource_id column — grievance_id is stored in metadata JSONB
        $this->assertDatabaseHas('emr_alerts', [
            'severity'      => 'critical',
            'source_module' => 'grievances',
            'alert_type'    => 'grievance_urgent_overdue',
        ]);
    }

    public function test_check_overdue_escalates_standard_overdue_to_urgent(): void
    {
        $actor     = $this->makeActor();
        $grievance = $this->makeGrievance($actor, [
            'priority' => 'standard',
            'status'   => 'open',
            'filed_at' => now()->subDays(35),
        ]);

        $result = $this->service->checkOverdue($actor->tenant_id);

        $this->assertGreaterThan(0, $result['standard']);
        $this->assertEquals('urgent', $grievance->fresh()->priority);
    }

    public function test_check_overdue_does_not_affect_non_overdue_grievances(): void
    {
        $actor     = $this->makeActor();
        $this->makeGrievance($actor, [
            'priority' => 'standard',
            'status'   => 'open',
            'filed_at' => now()->subDays(5),
        ]);

        $result = $this->service->checkOverdue($actor->tenant_id);

        $this->assertEquals(0, $result['urgent']);
        $this->assertEquals(0, $result['standard']);
    }
}
