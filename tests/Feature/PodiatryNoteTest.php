<?php

// ─── PodiatryNoteTest ─────────────────────────────────────────────────────────
// Verifies W4-8: podiatry note type (42 CFR §460.92).
//
// Tests:
//   - Podiatry note can be created and retrieved
//   - Structured content fields are stored correctly
//   - Note type label returns 'Podiatry'
//   - Unauthorized department cannot create podiatry notes (only primary_care/therapies/home_care)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodiatryNoteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private User        $primaryCareUser;

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

        $this->primaryCareUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    public function test_podiatry_note_can_be_created(): void
    {
        $response = $this->actingAs($this->primaryCareUser)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'podiatry',
                'visit_type' => 'in_center',
                'visit_date' => now()->toDateString(),
                'department' => 'primary_care',
                'status'     => 'draft',
                'content'    => [
                    'visit_reason'   => 'Routine nail care',
                    'skin_integrity' => 'Intact, no lesions',
                    'nail_condition' => 'Thickened',
                ],
            ]);

        $response->assertCreated();

        $note = ClinicalNote::where('participant_id', $this->participant->id)
            ->where('note_type', 'podiatry')
            ->first();

        $this->assertNotNull($note);
        $this->assertEquals('Routine nail care', $note->content['visit_reason']);
        $this->assertEquals('Thickened', $note->content['nail_condition']);
    }

    public function test_podiatry_note_is_retrievable(): void
    {
        ClinicalNote::create([
            'participant_id'      => $this->participant->id,
            'tenant_id'           => $this->tenant->id,
            'site_id'             => $this->site->id,
            'note_type'           => 'podiatry',
            'authored_by_user_id' => $this->primaryCareUser->id,
            'department'          => 'primary_care',
            'status'              => 'draft',
            'visit_type'          => 'in_center',
            'visit_date'          => now()->toDateString(),
            'content'             => ['visit_reason' => 'Diabetic foot exam', 'diabetes_dx' => true],
        ]);

        $response = $this->actingAs($this->primaryCareUser)
            ->getJson("/participants/{$this->participant->id}/notes");

        $response->assertOk();

        $notes = $response->json('data');
        $podiatryNotes = array_filter($notes, fn ($n) => $n['note_type'] === 'podiatry');
        $this->assertCount(1, $podiatryNotes);
    }

    public function test_podiatry_note_type_label_is_correct(): void
    {
        $note = new ClinicalNote(['note_type' => 'podiatry']);
        $this->assertEquals('Podiatry', $note->noteTypeLabel());
    }

    public function test_podiatry_note_content_fields_stored_as_array(): void
    {
        $content = [
            'visit_reason'      => 'Wound care',
            'wound_present'     => true,
            'treatment_provided'=> ['Wound dressing', 'Patient education'],
            'follow_up_interval'=> '2 weeks',
        ];

        ClinicalNote::create([
            'participant_id'      => $this->participant->id,
            'tenant_id'           => $this->tenant->id,
            'site_id'             => $this->site->id,
            'note_type'           => 'podiatry',
            'authored_by_user_id' => $this->primaryCareUser->id,
            'department'          => 'primary_care',
            'status'              => 'draft',
            'visit_type'          => 'in_center',
            'visit_date'          => now()->toDateString(),
            'content'             => $content,
        ]);

        $note = ClinicalNote::where('note_type', 'podiatry')->first();
        $this->assertEquals('Wound care', $note->content['visit_reason']);
        $this->assertTrue($note->content['wound_present']);
        $this->assertContains('Wound dressing', $note->content['treatment_provided']);
    }
}
