<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private User        $otherTenantUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'APT',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $otherTenant = Tenant::factory()->create();
        $this->otherTenantUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_appointment_returns_201(): void
    {
        $start = Carbon::tomorrow()->setHour(10)->setMinute(0);
        $end   = $start->copy()->addHour();

        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'clinic_visit',
                'scheduled_start'  => $start->toIso8601String(),
                'scheduled_end'    => $end->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_appointments', [
            'participant_id'   => $this->participant->id,
            'appointment_type' => 'clinic_visit',
            'status'           => 'scheduled',
            'tenant_id'        => $this->tenant->id,
        ]);
    }

    public function test_create_appointment_validates_required_fields(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_type', 'scheduled_start', 'scheduled_end']);
    }

    public function test_scheduled_end_must_be_after_start(): void
    {
        $start = Carbon::tomorrow()->setHour(10);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'clinic_visit',
                'scheduled_start'  => $start->toIso8601String(),
                'scheduled_end'    => $start->copy()->subHour()->toIso8601String(), // end before start
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_end']);
    }

    // ── Conflict detection ────────────────────────────────────────────────────

    public function test_overlapping_appointment_returns_409(): void
    {
        $start = Carbon::tomorrow()->setHour(10)->setMinute(0);
        $end   = $start->copy()->addHour();

        // Create existing appointment
        Appointment::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'scheduled_start' => $start,
            'scheduled_end'   => $end,
            'status'          => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        // Try to create overlapping appointment (30 min inside existing)
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'therapy_pt',
                'scheduled_start'  => $start->copy()->addMinutes(30)->toIso8601String(),
                'scheduled_end'    => $end->copy()->addMinutes(30)->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict');
    }

    public function test_adjacent_appointments_do_not_conflict(): void
    {
        $firstStart = Carbon::tomorrow()->setHour(9)->setMinute(0);
        $firstEnd   = $firstStart->copy()->addHour();

        // First appointment: 9-10
        Appointment::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'scheduled_start' => $firstStart,
            'scheduled_end'   => $firstEnd,
            'status'          => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        // Second appointment: 10-11 (adjacent, not overlapping)
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'clinic_visit',
                'scheduled_start'  => $firstEnd->toIso8601String(),
                'scheduled_end'    => $firstEnd->copy()->addHour()->toIso8601String(),
            ])
            ->assertStatus(201);
    }

    public function test_cancelled_appointment_does_not_block_slot(): void
    {
        $start = Carbon::tomorrow()->setHour(10)->setMinute(0);
        $end   = $start->copy()->addHour();

        // Cancelled appointment in the same slot
        Appointment::factory()->cancelled()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'scheduled_start' => $start,
            'scheduled_end'   => $end,
            'created_by_user_id' => $this->user->id,
        ]);

        // New appointment in the same slot — should succeed (cancelled don't conflict)
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'clinic_visit',
                'scheduled_start'  => $start->toIso8601String(),
                'scheduled_end'    => $end->toIso8601String(),
            ])
            ->assertStatus(201);
    }

    public function test_transport_conflict_returns_409(): void
    {
        $start = Carbon::tomorrow()->setHour(10)->setMinute(0);

        // Existing transport appointment at 10:00
        Appointment::factory()->withTransport()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'scheduled_start' => $start,
            'scheduled_end'   => $start->copy()->addHour(),
            'status'          => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        // New transport appointment at 11:00 (within 2-hour window of 10:00)
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type'  => 'specialist',
                'scheduled_start'   => $start->copy()->addHour()->toIso8601String(),
                'scheduled_end'     => $start->copy()->addHours(2)->toIso8601String(),
                'transport_required'=> true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'transport_conflict');
    }

    // ── Status transitions ─────────────────────────────────────────────────────

    public function test_cancel_appointment_requires_reason(): void
    {
        $appt = Appointment::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'site_id'        => $this->site->id,
            'status'         => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        // No reason — should fail
        $this->actingAs($this->user)
            ->patchJson("/participants/{$this->participant->id}/appointments/{$appt->id}/cancel", [])
            ->assertStatus(422);
    }

    public function test_cancel_appointment_with_reason_succeeds(): void
    {
        $appt = Appointment::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'site_id'        => $this->site->id,
            'status'         => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/participants/{$this->participant->id}/appointments/{$appt->id}/cancel", [
                'cancellation_reason' => 'Participant hospitalized',
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_appointments', [
            'id'                  => $appt->id,
            'status'              => 'cancelled',
            'cancellation_reason' => 'Participant hospitalized',
        ]);
    }

    public function test_complete_appointment(): void
    {
        $appt = Appointment::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'site_id'        => $this->site->id,
            'status'         => 'scheduled',
            'created_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/participants/{$this->participant->id}/appointments/{$appt->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('emr_appointments', ['id' => $appt->id, 'status' => 'completed']);
    }

    public function test_no_show_appointment_logs_audit(): void
    {
        $appt = Appointment::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'site_id'        => $this->site->id,
            'status'         => 'confirmed',
            'created_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/participants/{$this->participant->id}/appointments/{$appt->id}/no-show")
            ->assertOk();

        $this->assertDatabaseHas('emr_appointments', ['id' => $appt->id, 'status' => 'no_show']);
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'       => 'appointment.no_show',
            'resource_type'=> 'appointment',
            'resource_id'  => $appt->id,
        ]);
    }

    public function test_completed_appointment_cannot_be_cancelled(): void
    {
        $appt = Appointment::factory()->completed()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'site_id'        => $this->site->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/participants/{$this->participant->id}/appointments/{$appt->id}/cancel", [
                'cancellation_reason' => 'Should not work',
            ])
            ->assertStatus(422);
    }

    // ── Tenant isolation ───────────────────────────────────────────────────────

    public function test_cannot_access_appointments_for_different_tenant_participant(): void
    {
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($this->otherTenantUser->tenant_id)
            ->create(['site_id' => Site::factory()->create(['tenant_id' => $this->otherTenantUser->tenant_id, 'mrn_prefix' => 'OTH'])->id]);

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/appointments")
            ->assertStatus(403);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_creating_appointment_logs_audit(): void
    {
        $start = Carbon::tomorrow()->setHour(14);
        $end   = $start->copy()->addHour();

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/appointments", [
                'appointment_type' => 'clinic_visit',
                'scheduled_start'  => $start->toIso8601String(),
                'scheduled_end'    => $end->toIso8601String(),
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'       => 'appointment.created',
            'resource_type'=> 'appointment',
            'user_id'      => $this->user->id,
        ]);
    }
}
