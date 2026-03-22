<?php

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalNoteTest extends TestCase
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
            'mrn_prefix' => 'TEST',
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

    // ─── Create draft note ────────────────────────────────────────────────────

    public function test_create_draft_note_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'progress_nursing',
                'visit_date' => '2025-06-01',
                'visit_type' => 'in_center',
                'department' => 'primary_care',
                'content'    => ['notes' => 'Participant stable.'],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_clinical_notes', [
            'participant_id'      => $this->participant->id,
            'note_type'           => 'progress_nursing',
            'status'              => ClinicalNote::STATUS_DRAFT,
            'authored_by_user_id' => $this->user->id,
        ]);
    }

    public function test_create_soap_note_stores_subjective_objective_assessment_plan(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'   => 'soap',
                'visit_date'  => '2025-06-01',
                'visit_type'  => 'in_center',
                'department'  => 'primary_care',
                'subjective'  => 'Patient reports mild headache.',
                'objective'   => 'BP 130/80. Alert and oriented.',
                'assessment'  => 'Tension headache, hypertension stable.',
                'plan'        => 'Continue current meds. RTC 30 days.',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_clinical_notes', [
            'participant_id' => $this->participant->id,
            'note_type'      => 'soap',
            'subjective'     => 'Patient reports mild headache.',
        ]);
    }

    public function test_create_note_requires_valid_note_type(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'not_a_real_type',
                'visit_date' => '2025-06-01',
                'visit_type' => 'in_center',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note_type']);
    }

    public function test_create_note_requires_visit_date(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'progress_nursing',
                'visit_type' => 'in_center',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['visit_date']);
    }

    public function test_new_note_always_starts_as_draft(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'dietary',
                'visit_date' => '2025-06-01',
                'visit_type' => 'in_center',
                'department' => 'dietary',
                'status'     => ClinicalNote::STATUS_SIGNED,  // should be overridden
            ]);

        $response->assertCreated();
        $this->assertEquals(ClinicalNote::STATUS_DRAFT, $response->json('status'));
    }

    // ─── Edit draft ───────────────────────────────────────────────────────────

    public function test_author_can_edit_draft_note(): void
    {
        $note = ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/notes/{$note->id}", [
                'content' => ['notes' => 'Updated content.'],
            ])
            ->assertOk();

        $this->assertEquals('Updated content.', $note->fresh()->content['notes']);
    }

    public function test_non_author_cannot_edit_draft_note(): void
    {
        $otherUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
        ]);
        $note = ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($otherUser)
            ->putJson("/participants/{$this->participant->id}/notes/{$note->id}", [
                'content' => ['notes' => 'Tampered.'],
            ])
            ->assertForbidden();
    }

    // ─── Sign note ────────────────────────────────────────────────────────────

    public function test_author_can_sign_draft_note(): void
    {
        $note = ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes/{$note->id}/sign")
            ->assertOk();

        $fresh = $note->fresh();
        $this->assertEquals(ClinicalNote::STATUS_SIGNED, $fresh->status);
        $this->assertEquals($this->user->id, $fresh->signed_by_user_id);
        $this->assertNotNull($fresh->signed_at);
    }

    public function test_non_author_cannot_sign_note(): void
    {
        $otherUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
        ]);
        $note = ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($otherUser)
            ->postJson("/participants/{$this->participant->id}/notes/{$note->id}/sign")
            ->assertForbidden();
    }

    public function test_signed_note_cannot_be_edited(): void
    {
        $note = ClinicalNote::factory()
            ->signed()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/notes/{$note->id}", [
                'content' => ['notes' => 'Illegal edit.'],
            ])
            ->assertForbidden();
    }

    public function test_signed_note_cannot_be_signed_again(): void
    {
        $note = ClinicalNote::factory()
            ->signed()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes/{$note->id}/sign")
            ->assertForbidden();
    }

    // ─── Addendum ─────────────────────────────────────────────────────────────

    public function test_addendum_creates_child_note_with_parent_id(): void
    {
        $parent = ClinicalNote::factory()
            ->signed()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes/{$parent->id}/addendum", [
                'note_type'  => 'addendum',
                'visit_date' => now()->format('Y-m-d'),
                'visit_type' => 'in_center',
                'department' => 'primary_care',
                'content'    => ['notes' => 'Addendum: correcting prior note.'],
            ]);

        $response->assertCreated();
        $addendum = ClinicalNote::find($response->json('id'));
        $this->assertEquals($parent->id, $addendum->parent_note_id);
        $this->assertEquals('addendum', $addendum->note_type);
    }

    public function test_cannot_add_addendum_to_draft_note(): void
    {
        $draft = ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'authored_by_user_id' => $this->user->id,
            ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes/{$draft->id}/addendum", [
                'note_type'  => 'addendum',
                'visit_date' => now()->format('Y-m-d'),
                'visit_type' => 'in_center',
                'department' => 'primary_care',
            ])
            ->assertStatus(422);
    }

    // ─── Late entry ───────────────────────────────────────────────────────────

    public function test_late_entry_requires_reason(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'    => 'progress_nursing',
                'visit_date'   => '2025-01-01',
                'visit_type'   => 'in_center',
                'department'   => 'primary_care',
                'is_late_entry' => true,
                // late_entry_reason intentionally omitted
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['late_entry_reason']);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_access_notes_from_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/notes")
            ->assertForbidden();
    }

    // ─── Department note isolation ────────────────────────────────────────────

    public function test_dietary_user_cannot_see_soap_notes_from_primary_care(): void
    {
        // Create a primary_care SOAP note
        ClinicalNote::factory()
            ->signed()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'note_type'           => 'soap',
                'department'          => 'primary_care',
                'authored_by_user_id' => $this->user->id,
            ]);

        $dietaryUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'dietary',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($dietaryUser)
            ->getJson("/participants/{$this->participant->id}/notes");

        $response->assertOk();
        // Dietary user is scoped to their own department — soap/primary_care note should be invisible
        $notes = $response->json('data');
        $noteTypes = array_column($notes, 'note_type');
        $this->assertNotContains('soap', $noteTypes, 'Dietary user must not see primary care SOAP notes.');
    }

    public function test_dietary_user_can_see_own_dietary_notes(): void
    {
        $dietaryUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'dietary',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        // Create a dietary note authored by the dietary user
        ClinicalNote::factory()
            ->draft()
            ->for($this->participant)
            ->create([
                'tenant_id'           => $this->tenant->id,
                'note_type'           => 'dietary',
                'department'          => 'dietary',
                'authored_by_user_id' => $dietaryUser->id,
            ]);

        $response = $this->actingAs($dietaryUser)
            ->getJson("/participants/{$this->participant->id}/notes");

        $response->assertOk();
        $notes = $response->json('data');
        $this->assertCount(1, $notes, 'Dietary user should see their own dietary note.');
        $this->assertEquals('dietary', $notes[0]['department']);
    }

    public function test_it_admin_can_see_all_note_types(): void
    {
        $itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        // Create notes from two different departments
        ClinicalNote::factory()->draft()->for($this->participant)->create([
            'tenant_id'           => $this->tenant->id,
            'note_type'           => 'soap',
            'department'          => 'primary_care',
            'authored_by_user_id' => $this->user->id,
        ]);
        ClinicalNote::factory()->draft()->for($this->participant)->create([
            'tenant_id'           => $this->tenant->id,
            'note_type'           => 'dietary',
            'department'          => 'dietary',
            'authored_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($itAdmin)
            ->getJson("/participants/{$this->participant->id}/notes");

        $response->assertOk();
        // IT admin sees all notes — both departments visible
        $this->assertCount(2, $response->json('data'));
    }

    // ─── Audit log ────────────────────────────────────────────────────────────

    public function test_creating_note_writes_audit_log(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/notes", [
                'note_type'  => 'social_work',
                'visit_date' => '2025-06-01',
                'visit_type' => 'in_center',
                'department' => 'social_work',
            ]);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'participant.note.created',
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->user->id,
            'resource_id' => $this->participant->id,
        ]);
    }
}
