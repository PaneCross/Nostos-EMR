<?php

namespace Tests\Unit;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use App\Services\SdrDeadlineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SdrDeadlineServiceTest extends TestCase
{
    use RefreshDatabase;

    private SdrDeadlineService $service;
    private Tenant             $tenant;
    private Participant        $participant;
    private User               $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'SDS',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($site->id)
            ->create();

        $this->service = app(SdrDeadlineService::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeSdr(int $hoursAgo, string $status = 'in_progress'): Sdr
    {
        // Bypass boot() by using direct DB insert approach via factory state
        $submittedAt = Carbon::now()->subHours($hoursAgo);
        return Sdr::factory()->create([
            'participant_id'        => $this->participant->id,
            'tenant_id'             => $this->tenant->id,
            'requesting_user_id'    => $this->user->id,
            'requesting_department' => 'primary_care',
            'assigned_department'   => 'pharmacy',
            'status'                => $status,
            'submitted_at'          => $submittedAt,
            // due_at auto-set by boot() = submitted_at + 72h
        ]);
    }

    // ─── 24h warning ─────────────────────────────────────────────────────────

    public function test_sdr_due_within_24h_creates_warning_alert(): void
    {
        $sdr = $this->makeSdr(50); // 50h ago → 22h remaining → within 24h warning

        $result = $this->service->processBatch(collect([$sdr]));

        $this->assertGreaterThan(0, $result['info'] + $result['warning']);

        // A warning alert should be created for the assigned department
        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'source_module'  => 'sdr',
            'alert_type'     => 'sdr_warning_24h',
        ]);
    }

    // ─── 8h urgent warning ────────────────────────────────────────────────────

    public function test_sdr_due_within_8h_creates_urgent_alert(): void
    {
        $sdr = $this->makeSdr(65); // 65h ago → 7h remaining → within 8h urgent

        $result = $this->service->processBatch(collect([$sdr]));

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'source_module'  => 'sdr',
            'alert_type'     => 'sdr_warning_8h',
            'severity'       => 'warning',
        ]);
    }

    // ─── Overdue escalation ───────────────────────────────────────────────────

    public function test_overdue_sdr_escalates_and_creates_critical_alert(): void
    {
        $sdr = $this->makeSdr(80); // 80h ago → 8h overdue

        $result = $this->service->processBatch(collect([$sdr]));

        $this->assertGreaterThan(0, $result['escalated']);

        // SDR should be marked escalated
        $this->assertDatabaseHas('emr_sdrs', [
            'id'       => $sdr->id,
            'escalated'=> true,
        ]);

        // Critical alert created
        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'sdr_overdue',
            'severity'       => 'critical',
        ]);
    }

    // ─── Completed SDR not escalated ─────────────────────────────────────────

    public function test_completed_sdr_is_not_escalated(): void
    {
        // Create an already-completed SDR using the factory state
        $sdr = Sdr::factory()->completed()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        // processBatch receives open SDRs — pass it anyway to verify safeguard
        $result = $this->service->processBatch(collect([$sdr]));

        $this->assertSame(0, $result['escalated']);
        $this->assertDatabaseMissing('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'sdr_overdue',
        ]);
    }

    // ─── Alert deduplication ─────────────────────────────────────────────────

    public function test_does_not_create_duplicate_overdue_alert(): void
    {
        $sdr = $this->makeSdr(80);

        // First batch run → creates alert + escalates
        $this->service->processBatch(collect([$sdr]));

        $alertCountBefore = Alert::where('alert_type', 'sdr_overdue')
            ->where('participant_id', $this->participant->id)
            ->count();

        // Second batch run on same SDR → should NOT create another alert
        $sdr->refresh();
        $this->service->processBatch(collect([$sdr]));

        $alertCountAfter = Alert::where('alert_type', 'sdr_overdue')
            ->where('participant_id', $this->participant->id)
            ->count();

        $this->assertSame($alertCountBefore, $alertCountAfter);
    }
}
