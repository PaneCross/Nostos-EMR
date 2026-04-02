<?php

// ─── TransitionOfCareTest ─────────────────────────────────────────────────────
// Verifies W4-8: transition_of_care note type.
//
// Tests:
//   - ADT A01 creates a DRAFT transition_of_care note with transition_type='hospital_admission'
//   - ADT A03 creates a DRAFT transition_of_care note with transition_type='hospital_discharge'
//   - Note content includes facility name from ADT payload
//   - Note status is 'draft' (not signed — requires clinician review)
//   - Unknown MRN does NOT create a note (graceful failure)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\ProcessHl7AdtJob;
use App\Models\ClinicalNote;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransitionOfCareTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private User        $itAdminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->participant = Participant::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'site_id'           => $this->site->id,
            'enrollment_status' => 'enrolled',
        ]);

        // IT admin user used as system author for auto-created notes
        $this->itAdminUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'is_active'  => true,
        ]);
    }

    private function makeIntegrationLog(): IntegrationLog
    {
        return IntegrationLog::create([
            'tenant_id'      => $this->tenant->id,
            'connector_type' => 'hl7_adt',
            'direction'      => 'inbound',
            'status'         => 'pending',
            'raw_payload'    => '{}',
            'created_at'     => now(),
        ]);
    }

    public function test_adt_a01_creates_draft_transition_note_with_admission_type(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A01',
                'patient_mrn'  => $this->participant->mrn,
                'facility'     => 'General Hospital',
            ],
            $this->tenant->id
        );

        $note = ClinicalNote::where('participant_id', $this->participant->id)
            ->where('note_type', 'transition_of_care')
            ->first();

        $this->assertNotNull($note, 'A01 should create a transition_of_care note');
        $this->assertEquals(ClinicalNote::STATUS_DRAFT, $note->status);
        $this->assertEquals('hospital_admission', $note->content['transition_type']);
    }

    public function test_adt_a01_note_includes_facility_name(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A01',
                'patient_mrn'  => $this->participant->mrn,
                'facility'     => 'St. Mary Medical Center',
            ],
            $this->tenant->id
        );

        $note = ClinicalNote::where('participant_id', $this->participant->id)
            ->where('note_type', 'transition_of_care')
            ->first();

        $this->assertEquals('St. Mary Medical Center', $note->content['facility']);
    }

    public function test_adt_a03_creates_draft_transition_note_with_discharge_type(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A03',
                'patient_mrn'  => $this->participant->mrn,
                'facility'     => 'General Hospital',
            ],
            $this->tenant->id
        );

        $note = ClinicalNote::where('participant_id', $this->participant->id)
            ->where('note_type', 'transition_of_care')
            ->first();

        $this->assertNotNull($note, 'A03 should create a transition_of_care note');
        $this->assertEquals(ClinicalNote::STATUS_DRAFT, $note->status);
        $this->assertEquals('hospital_discharge', $note->content['transition_type']);
    }

    public function test_transition_note_is_auto_populated_flag_set(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A01',
                'patient_mrn'  => $this->participant->mrn,
                'facility'     => 'Test Hospital',
            ],
            $this->tenant->id
        );

        $note = ClinicalNote::where('participant_id', $this->participant->id)
            ->where('note_type', 'transition_of_care')
            ->first();

        $this->assertTrue($note->content['auto_populated']);
    }

    public function test_unknown_mrn_does_not_create_transition_note(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A01',
                'patient_mrn'  => 'UNKNOWN-99999',
                'facility'     => 'General Hospital',
            ],
            $this->tenant->id
        );

        $count = ClinicalNote::where('note_type', 'transition_of_care')->count();
        $this->assertEquals(0, $count);
    }

    public function test_a08_update_does_not_create_transition_note(): void
    {
        $logEntry = $this->makeIntegrationLog();

        ProcessHl7AdtJob::dispatchSync(
            $logEntry->id,
            [
                'message_type' => 'A08',
                'patient_mrn'  => $this->participant->mrn,
            ],
            $this->tenant->id
        );

        $count = ClinicalNote::where('note_type', 'transition_of_care')->count();
        $this->assertEquals(0, $count);
    }
}
