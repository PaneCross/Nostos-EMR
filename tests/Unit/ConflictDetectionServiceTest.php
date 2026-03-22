<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConflictDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConflictDetectionService $service;
    private Tenant $tenant;
    private Site $site;
    private Participant $participant;
    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConflictDetectionService();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'CDS',
        ]);
        $this->creator = User::factory()->create([
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAppointment(Carbon $start, Carbon $end, string $status = 'scheduled', bool $transportRequired = false): Appointment
    {
        return Appointment::factory()->create([
            'participant_id'   => $this->participant->id,
            'tenant_id'        => $this->tenant->id,
            'site_id'          => $this->site->id,
            'scheduled_start'  => $start,
            'scheduled_end'    => $end,
            'status'           => $status,
            'transport_required' => $transportRequired,
            'created_by_user_id' => $this->creator->id,
        ]);
    }

    // ── Participant conflict tests ─────────────────────────────────────────────

    public function test_no_conflict_when_no_existing_appointments(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();

        $this->assertFalse(
            $this->service->checkParticipantConflict($this->participant->id, $start, $end)
        );
    }

    public function test_overlap_returns_true(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();
        $this->makeAppointment($start, $end);

        // Partial overlap: 10:30–11:30 overlaps 10:00–11:00
        $this->assertTrue(
            $this->service->checkParticipantConflict(
                $this->participant->id,
                $start->copy()->addMinutes(30),
                $end->copy()->addMinutes(30)
            )
        );
    }

    public function test_exact_same_time_returns_true(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();
        $this->makeAppointment($start, $end);

        $this->assertTrue(
            $this->service->checkParticipantConflict($this->participant->id, $start, $end)
        );
    }

    public function test_adjacent_after_returns_false(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();
        $this->makeAppointment($start, $end);

        // New: 11:00–12:00 (starts exactly when existing ends — no overlap)
        $this->assertFalse(
            $this->service->checkParticipantConflict(
                $this->participant->id,
                $end->copy(),
                $end->copy()->addHour()
            )
        );
    }

    public function test_adjacent_before_returns_false(): void
    {
        $start = Carbon::tomorrow()->setHour(11);
        $end   = $start->copy()->addHour();
        $this->makeAppointment($start, $end);

        // New: 10:00–11:00 (ends exactly when existing starts — no overlap)
        $this->assertFalse(
            $this->service->checkParticipantConflict(
                $this->participant->id,
                $start->copy()->subHour(),
                $start->copy()
            )
        );
    }

    public function test_cancelled_appointment_excluded_from_conflict_check(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();
        $this->makeAppointment($start, $end, 'cancelled');

        // Cancelled appointment doesn't block the slot
        $this->assertFalse(
            $this->service->checkParticipantConflict($this->participant->id, $start, $end)
        );
    }

    public function test_exclude_id_param_excludes_appointment_from_check(): void
    {
        $start = Carbon::tomorrow()->setHour(10);
        $end   = $start->copy()->addHour();
        $appt  = $this->makeAppointment($start, $end);

        // Without excludeId → conflict with self
        $this->assertTrue(
            $this->service->checkParticipantConflict($this->participant->id, $start, $end)
        );

        // With excludeId → no conflict (updating self)
        $this->assertFalse(
            $this->service->checkParticipantConflict($this->participant->id, $start, $end, $appt->id)
        );
    }

    // ── Transport conflict tests ──────────────────────────────────────────────

    public function test_no_transport_conflict_when_no_transport_appointments(): void
    {
        $time = Carbon::tomorrow()->setHour(10);
        // Existing appointment without transport
        $this->makeAppointment($time, $time->copy()->addHour(), 'scheduled', false);

        $this->assertFalse(
            $this->service->checkTransportConflict($this->participant->id, $time)
        );
    }

    public function test_transport_conflict_within_2_hours_returns_true(): void
    {
        $existing = Carbon::tomorrow()->setHour(10);
        $this->makeAppointment($existing, $existing->copy()->addHour(), 'scheduled', true);

        // New transport appointment at 11:00 — within 2-hour window of 10:00
        $this->assertTrue(
            $this->service->checkTransportConflict(
                $this->participant->id,
                $existing->copy()->addHour()
            )
        );
    }

    public function test_transport_no_conflict_outside_2_hour_window(): void
    {
        $existing = Carbon::tomorrow()->setHour(8);
        $this->makeAppointment($existing, $existing->copy()->addHour(), 'scheduled', true);

        // New transport appointment at 11:00 — more than 2 hours after 8:00
        $this->assertFalse(
            $this->service->checkTransportConflict(
                $this->participant->id,
                $existing->copy()->addHours(3)
            )
        );
    }

    public function test_transport_conflict_excludes_cancelled_appointments(): void
    {
        $time = Carbon::tomorrow()->setHour(10);
        $this->makeAppointment($time, $time->copy()->addHour(), 'cancelled', true);

        // Cancelled transport appt does not block the 2-hour window
        $this->assertFalse(
            $this->service->checkTransportConflict(
                $this->participant->id,
                $time->copy()->addHour()
            )
        );
    }

    public function test_transport_exclude_id_param_works(): void
    {
        $time = Carbon::tomorrow()->setHour(10);
        $appt = $this->makeAppointment($time, $time->copy()->addHour(), 'scheduled', true);

        // Without excludeId → conflict with self
        $this->assertTrue(
            $this->service->checkTransportConflict($this->participant->id, $time)
        );

        // With excludeId → no conflict (updating self)
        $this->assertFalse(
            $this->service->checkTransportConflict($this->participant->id, $time, $appt->id)
        );
    }
}
