<?php

namespace Tests\Unit;

use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AdlThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdlThresholdServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdlThresholdService $service;
    private Tenant      $tenant;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AdlThresholdService::class);

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'ADL',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($site->id)
            ->create();
    }

    /**
     * Helper: create a threshold for the participant.
     */
    private function setThreshold(string $category, string $level): AdlThreshold
    {
        return AdlThreshold::updateOrCreate(
            ['participant_id' => $this->participant->id, 'adl_category' => $category],
            ['threshold_level' => $level, 'set_by_user_id' => null, 'set_at' => now()]
        );
    }

    /**
     * Helper: make an unsaved AdlRecord model (not persisted to DB).
     */
    private function makeRecord(string $category, string $level): AdlRecord
    {
        $record = new AdlRecord([
            'participant_id'      => $this->participant->id,
            'tenant_id'           => $this->tenant->id,
            'recorded_by_user_id' => $this->user->id,
            'adl_category'        => $category,
            'independence_level'  => $level,
            'threshold_breached'  => false,
            'recorded_at'         => now(),
        ]);
        $record->save();
        return $record->fresh();
    }

    // ─── Breach detection ─────────────────────────────────────────────────────

    public function test_level_worse_than_threshold_returns_true(): void
    {
        // Threshold: limited_assist (index 2); record: extensive_assist (index 3) → breach
        $this->setThreshold('bathing', 'limited_assist');
        $record = $this->makeRecord('bathing', 'extensive_assist');

        $this->assertTrue($this->service->checkBreach($record));
    }

    public function test_total_dependent_always_breaches_any_lesser_threshold(): void
    {
        // Threshold: supervision (index 1); record: total_dependent (index 4) → breach
        $this->setThreshold('dressing', 'supervision');
        $record = $this->makeRecord('dressing', 'total_dependent');

        $this->assertTrue($this->service->checkBreach($record));
    }

    public function test_extensive_assist_breaches_supervision_threshold(): void
    {
        $this->setThreshold('grooming', 'supervision');
        $record = $this->makeRecord('grooming', 'extensive_assist');

        $this->assertTrue($this->service->checkBreach($record));
    }

    // ─── No breach ────────────────────────────────────────────────────────────

    public function test_level_equal_to_threshold_returns_false(): void
    {
        // Threshold: limited_assist (index 2); record: limited_assist (index 2) → no breach
        $this->setThreshold('eating', 'limited_assist');
        $record = $this->makeRecord('eating', 'limited_assist');

        $this->assertFalse($this->service->checkBreach($record));
    }

    public function test_level_better_than_threshold_returns_false(): void
    {
        // Threshold: extensive_assist (index 3); record: supervision (index 1) → no breach
        $this->setThreshold('ambulation', 'extensive_assist');
        $record = $this->makeRecord('ambulation', 'supervision');

        $this->assertFalse($this->service->checkBreach($record));
    }

    public function test_independent_never_breaches_any_threshold(): void
    {
        // Threshold: total_dependent (index 4); record: independent (index 0) → no breach
        $this->setThreshold('continence', 'total_dependent');
        $record = $this->makeRecord('continence', 'independent');

        $this->assertFalse($this->service->checkBreach($record));
    }

    // ─── No threshold configured ──────────────────────────────────────────────

    public function test_no_threshold_set_returns_false(): void
    {
        // No threshold row exists for this category
        $record = $this->makeRecord('communication', 'total_dependent');

        $this->assertFalse($this->service->checkBreach($record));
    }

    public function test_threshold_for_different_category_does_not_trigger(): void
    {
        // Set threshold only for 'bathing' — record is for 'dressing'
        $this->setThreshold('bathing', 'supervision');
        $record = $this->makeRecord('dressing', 'total_dependent');

        $this->assertFalse($this->service->checkBreach($record));
    }

    // ─── handleBreach writes audit log ────────────────────────────────────────

    public function test_handle_breach_writes_audit_log(): void
    {
        $this->setThreshold('toileting', 'supervision');
        $record = $this->makeRecord('toileting', 'total_dependent');

        $this->service->handleBreach($record, $this->participant);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'participant.adl.threshold_breached',
            'resource_id' => $this->participant->id,
        ]);
    }

    public function test_handle_breach_sets_threshold_breached_flag(): void
    {
        $this->setThreshold('medication_management', 'limited_assist');
        $record = $this->makeRecord('medication_management', 'extensive_assist');

        $this->service->handleBreach($record, $this->participant);

        $this->assertDatabaseHas('emr_adl_records', [
            'id'                 => $record->id,
            'threshold_breached' => true,
        ]);
    }
}
