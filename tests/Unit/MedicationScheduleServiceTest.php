<?php

namespace Tests\Unit;

use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\MedicationScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private MedicationScheduleService $service;
    private Tenant                    $tenant;
    private Participant               $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MedicationScheduleService::class);

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'SCH',
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($site->id)
            ->create();
    }

    // ── generateDailyMar: creates eMAR records ────────────────────────────────

    public function test_generates_emar_records_for_daily_medication(): void
    {
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'status'     => 'active',
                'is_prn'     => false,
                'frequency'  => 'daily',
                'start_date' => now()->subMonth()->toDateString(),
            ]);

        $count = $this->service->generateDailyMar(Carbon::today());

        $this->assertEquals(1, $count);
        $this->assertDatabaseCount('emr_emar_records', 1);
        $this->assertDatabaseHas('emr_emar_records', [
            'participant_id' => $this->participant->id,
            'status'         => 'scheduled',
        ]);
    }

    public function test_generates_two_records_for_bid_medication(): void
    {
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'status'     => 'active',
                'is_prn'     => false,
                'frequency'  => 'BID',
                'start_date' => now()->subMonth()->toDateString(),
            ]);

        $count = $this->service->generateDailyMar(Carbon::today());

        $this->assertEquals(2, $count);
        $this->assertDatabaseCount('emr_emar_records', 2);
    }

    public function test_skips_prn_medications(): void
    {
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->prn()
            ->create([
                'status'    => 'active',
                'frequency' => 'PRN',
                'start_date'=> now()->subMonth()->toDateString(),
            ]);

        $count = $this->service->generateDailyMar(Carbon::today());

        $this->assertEquals(0, $count);
        $this->assertDatabaseCount('emr_emar_records', 0);
    }

    public function test_skips_discontinued_medications(): void
    {
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->discontinued()
            ->create([
                'frequency'  => 'daily',
                'start_date' => now()->subMonth()->toDateString(),
            ]);

        $count = $this->service->generateDailyMar(Carbon::today());

        $this->assertEquals(0, $count);
    }

    public function test_is_idempotent_on_second_run(): void
    {
        Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'status'     => 'active',
                'is_prn'     => false,
                'frequency'  => 'daily',
                'start_date' => now()->subMonth()->toDateString(),
            ]);

        $first  = $this->service->generateDailyMar(Carbon::today());
        $second = $this->service->generateDailyMar(Carbon::today());

        // Second run creates 0 new records (idempotent)
        $this->assertEquals(1, $first);
        $this->assertEquals(0, $second);
        $this->assertDatabaseCount('emr_emar_records', 1);
    }

    // ── getScheduledTimesForDate: frequency-to-time mapping ──────────────────

    public function test_daily_medication_returns_one_time(): void
    {
        $med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['frequency' => 'daily', 'start_date' => now()->subMonth()]);

        $times = $this->service->getScheduledTimesForDate($med, Carbon::today());

        $this->assertCount(1, $times);
        $this->assertEquals('08:00', $times[0]);
    }

    public function test_bid_medication_returns_two_times(): void
    {
        $med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['frequency' => 'BID', 'start_date' => now()->subMonth()]);

        $times = $this->service->getScheduledTimesForDate($med, Carbon::today());

        $this->assertCount(2, $times);
        $this->assertContains('08:00', $times);
        $this->assertContains('20:00', $times);
    }

    public function test_weekly_medication_only_fires_on_matching_day_of_week(): void
    {
        // Start date on a Monday
        $monday = Carbon::parse('last monday');
        $med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['frequency' => 'weekly', 'start_date' => $monday->toDateString()]);

        // Same day of week → should schedule
        $timesMonday = $this->service->getScheduledTimesForDate($med, $monday->copy());
        $this->assertCount(1, $timesMonday);

        // Different day of week → should NOT schedule
        $timesTuesday = $this->service->getScheduledTimesForDate($med, $monday->copy()->addDay());
        $this->assertCount(0, $timesTuesday);
    }

    public function test_once_medication_only_fires_on_start_date(): void
    {
        $startDate = Carbon::today();
        $med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['frequency' => 'once', 'start_date' => $startDate->toDateString()]);

        // On start date → schedule
        $timesOnDate = $this->service->getScheduledTimesForDate($med, $startDate->copy());
        $this->assertCount(1, $timesOnDate);

        // Next day → no schedule
        $timesNextDay = $this->service->getScheduledTimesForDate($med, $startDate->copy()->addDay());
        $this->assertCount(0, $timesNextDay);
    }

    public function test_prn_frequency_returns_no_times(): void
    {
        $med = Medication::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->prn()
            ->create(['frequency' => 'PRN', 'start_date' => now()->subMonth()]);

        $times = $this->service->getScheduledTimesForDate($med, Carbon::today());

        $this->assertCount(0, $times);
    }
}
