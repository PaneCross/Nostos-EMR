<?php

namespace Tests\Feature;

use App\Jobs\LateMarDetectionJob;
use App\Models\Alert;
use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LateMarDetectionJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private User        $nurse;
    private Medication  $medication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'LMD',
        ]);
        $this->nurse = User::factory()->create([
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

        $this->medication = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'drug_name' => 'Metoprolol',
                'status'    => 'active',
                'is_prn'    => false,
            ]);
    }

    // ── Core behavior ─────────────────────────────────────────────────────────

    public function test_marks_overdue_scheduled_record_as_late(): void
    {
        // Record scheduled 45 minutes ago (past the 30-min grace period)
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->overdue()   // scheduled_time = now() - 45min, status = 'scheduled'
            ->create(['medication_id' => $this->medication->id]);

        app(LateMarDetectionJob::class)->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_emar_records', [
            'participant_id' => $this->participant->id,
            'status'         => 'late',
        ]);
    }

    public function test_creates_alert_for_overdue_record(): void
    {
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->overdue()
            ->create(['medication_id' => $this->medication->id]);

        app(LateMarDetectionJob::class)->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'warning',
            'source_module'  => 'medications',
        ]);
    }

    public function test_does_not_mark_recent_scheduled_record_as_late(): void
    {
        // Record scheduled only 10 minutes ago (within grace period)
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'medication_id'  => $this->medication->id,
                'status'         => 'scheduled',
                'scheduled_time' => now()->subMinutes(10),  // Within 30-min grace period
            ]);

        app(LateMarDetectionJob::class)->handle(app(\App\Services\AlertService::class));

        // Record should still be 'scheduled', not 'late'
        $this->assertDatabaseHas('emr_emar_records', [
            'participant_id' => $this->participant->id,
            'status'         => 'scheduled',
        ]);
        $this->assertDatabaseMissing('emr_emar_records', [
            'participant_id' => $this->participant->id,
            'status'         => 'late',
        ]);
    }

    public function test_does_not_re_mark_already_late_records(): void
    {
        // Create a record already marked as 'late'
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->late()   // status = 'late', scheduled_time = now() - 2h
            ->create(['medication_id' => $this->medication->id]);

        app(LateMarDetectionJob::class)->handle(app(\App\Services\AlertService::class));

        // Should only have the 1 original record — no additional records created
        $this->assertDatabaseCount('emr_emar_records', 1);
        // And no additional alerts (already-late records are not reprocessed)
        $this->assertDatabaseCount('emr_alerts', 0);
    }

    public function test_creates_audit_log_for_each_late_dose(): void
    {
        EmarRecord::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->overdue()
            ->create(['medication_id' => $this->medication->id]);

        app(LateMarDetectionJob::class)->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'emar.late_dose_flagged',
            'resource_type' => 'emar_record',
        ]);
    }
}
