<?php

namespace Tests\Feature;

use App\Events\ClinicalNoteSignedEvent;
use App\Events\FlagAddedEvent;
use App\Events\ParticipantAdlBreachEvent;
use App\Events\SdrCreatedEvent;
use App\Events\VitalsRecordedEvent;
use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeEventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'RT',
        ]);
        $this->user = User::factory()->create([
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

    // ─── Sign note fires ClinicalNoteSignedEvent ──────────────────────────────

    public function test_signing_note_fires_clinical_note_signed_event(): void
    {
        Event::fake([ClinicalNoteSignedEvent::class]);

        $note = ClinicalNote::create([
            'participant_id'      => $this->participant->id,
            'tenant_id'           => $this->tenant->id,
            'site_id'             => $this->site->id,
            'note_type'           => 'soap',
            'authored_by_user_id' => $this->user->id,
            'department'          => 'primary_care',
            'status'              => 'draft',
            'visit_type'          => 'in_center',
            'visit_date'          => now()->format('Y-m-d'),
            'subjective'          => 'Participant stable.',
            'objective'           => 'Vitals within range.',
            'assessment'          => 'No acute concerns.',
            'plan'                => 'Continue current treatment.',
        ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes/{$note->id}/sign")
            ->assertOk();

        Event::assertDispatched(ClinicalNoteSignedEvent::class, function ($event) use ($note) {
            return $event->note->id === $note->id;
        });
    }

    // ─── Record vitals fires VitalsRecordedEvent ──────────────────────────────

    public function test_recording_vitals_fires_vitals_recorded_event(): void
    {
        Event::fake([VitalsRecordedEvent::class]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'bp_systolic'  => 130,
                'bp_diastolic' => 85,
                'pulse'        => 72,
                'recorded_at'  => now()->toDateTimeString(),
            ])
            ->assertStatus(201);

        Event::assertDispatched(VitalsRecordedEvent::class);
    }

    // ─── Flag added fires FlagAddedEvent ─────────────────────────────────────

    public function test_adding_flag_fires_flag_added_event(): void
    {
        Event::fake([FlagAddedEvent::class]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type'   => 'fall_risk',
                'severity'    => 'high',
                'description' => 'High fall risk — walker required.',
            ])
            ->assertStatus(201);

        Event::assertDispatched(FlagAddedEvent::class);
    }

    // ─── ADL breach fires ParticipantAdlBreachEvent ───────────────────────────

    public function test_adl_breach_fires_breach_event(): void
    {
        Event::fake([ParticipantAdlBreachEvent::class]);

        // Set threshold at 'limited_assist' — recording 'total_dependent' should breach
        AdlThreshold::updateOrCreate(
            ['participant_id' => $this->participant->id, 'adl_category' => 'bathing'],
            [
                'threshold_level' => 'limited_assist',
                'set_by_user_id'  => $this->user->id,
                'set_at'          => now(),
            ]
        );

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'       => 'bathing',
                'independence_level' => 'total_dependent',
                'recorded_at'        => now()->toDateTimeString(),
            ])
            ->assertStatus(201);

        // The observer + AdlThresholdService should broadcast the breach event
        Event::assertDispatched(ParticipantAdlBreachEvent::class);
    }

    public function test_no_breach_event_when_level_meets_threshold(): void
    {
        Event::fake([ParticipantAdlBreachEvent::class]);

        // Threshold at 'extensive_assist' — recording 'limited_assist' is BETTER → no breach
        AdlThreshold::updateOrCreate(
            ['participant_id' => $this->participant->id, 'adl_category' => 'dressing'],
            [
                'threshold_level' => 'extensive_assist',
                'set_by_user_id'  => $this->user->id,
                'set_at'          => now(),
            ]
        );

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/adl", [
                'adl_category'       => 'dressing',
                'independence_level' => 'limited_assist',
                'recorded_at'        => now()->toDateTimeString(),
            ])
            ->assertStatus(201);

        Event::assertNotDispatched(ParticipantAdlBreachEvent::class);
    }

    // ─── Submit SDR fires SdrCreatedEvent ─────────────────────────────────────

    public function test_submit_sdr_fires_sdr_created_event(): void
    {
        Event::fake([SdrCreatedEvent::class]);

        $this->actingAs($this->user)
            ->postJson('/sdrs', [
                'participant_id'      => $this->participant->id,
                'assigned_department' => 'pharmacy',
                'request_type'        => 'lab_order',
                'description'         => 'INR check — warfarin monitoring.',
                'priority'            => 'urgent',
            ])
            ->assertStatus(201);

        Event::assertDispatched(SdrCreatedEvent::class);
    }
}
