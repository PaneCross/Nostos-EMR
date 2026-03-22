<?php

// ─── DocumentUploadTest ───────────────────────────────────────────────────────
// Feature tests for participant document upload, listing, streaming, and
// soft-delete via DocumentController.
//
// Uses Storage::fake('local') — no real files written during test runs.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant      = Tenant::factory()->create();
        $this->site        = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'DOC',
        ]);
        $this->user        = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_upload_pdf_document(): void
    {
        $file = UploadedFile::fake()->create('consent.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/documents", [
                'file'              => $file,
                'document_category' => 'consent',
                'description'       => 'Signed PACE consent form',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('document.file_name', 'consent.pdf')
            ->assertJsonPath('document.document_category', 'consent')
            ->assertJsonPath('document.file_type', 'pdf');

        $this->assertDatabaseHas('emr_documents', [
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'document_category' => 'consent',
            'deleted_at'        => null,
        ]);
    }

    public function test_upload_rejects_file_over_20_mb(): void
    {
        // fake()->create() size is in KB; 21000 KB ≈ 20.5 MB
        $file = UploadedFile::fake()->create('huge.pdf', 21_000, 'application/pdf');

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/documents", [
                'file'              => $file,
                'document_category' => 'other',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_rejects_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('script.exe', 50, 'application/octet-stream');

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/documents", [
                'file'              => $file,
                'document_category' => 'other',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_rejects_invalid_category(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/documents", [
                'file'              => $file,
                'document_category' => 'not_a_real_category',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_category']);
    }

    // ── List ──────────────────────────────────────────────────────────────────

    public function test_can_list_participant_documents(): void
    {
        Document::factory()->count(3)->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
            'document_category' => 'consent',
        ]);
        // Document from another participant — should NOT appear
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
        Document::factory()->create([
            'participant_id'    => $otherParticipant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/documents");

        $response->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(3, 'data');
    }

    public function test_documents_list_respects_category_filter(): void
    {
        Document::factory()->count(2)->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
            'document_category' => 'lab_report',
        ]);
        Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
            'document_category' => 'consent',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/documents?category=lab_report");

        $response->assertOk()
            ->assertJsonPath('total', 2);
    }

    // ── Download ──────────────────────────────────────────────────────────────

    public function test_can_download_document(): void
    {
        // Put a fake file in local storage
        Storage::disk('local')->put('participants/1/test_doc.pdf', 'fake pdf content');

        $doc = Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
            'file_name'         => 'consent.pdf',
            'file_path'         => 'participants/1/test_doc.pdf',
            'file_type'         => 'pdf',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}/documents/{$doc->id}/download");

        $response->assertOk();
    }

    public function test_download_returns_404_for_missing_file(): void
    {
        $doc = Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
            'file_path'         => 'participants/1/nonexistent.pdf',
            'file_type'         => 'pdf',
        ]);

        $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}/documents/{$doc->id}/download")
            ->assertNotFound();
    }

    // ── Soft Delete ───────────────────────────────────────────────────────────

    public function test_uploader_can_soft_delete_their_document(): void
    {
        $doc = Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/participants/{$this->participant->id}/documents/{$doc->id}")
            ->assertOk();

        // Document is soft-deleted — still in DB but deleted_at is set
        $this->assertSoftDeleted('emr_documents', ['id' => $doc->id]);
    }

    public function test_non_uploader_cannot_delete_document(): void
    {
        $anotherUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'social_work',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $doc = Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id, // uploaded by a different user
        ]);

        $this->actingAs($anotherUser)
            ->deleteJson("/participants/{$this->participant->id}/documents/{$doc->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('emr_documents', ['id' => $doc->id, 'deleted_at' => null]);
    }

    public function test_it_admin_can_delete_any_document(): void
    {
        $admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $doc = Document::factory()->create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'uploaded_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/participants/{$this->participant->id}/documents/{$doc->id}")
            ->assertOk();

        $this->assertSoftDeleted('emr_documents', ['id' => $doc->id]);
    }

    // ── Cross-tenant isolation ─────────────────────────────────────────────────

    public function test_cross_tenant_user_cannot_access_participant_documents(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTH',
        ]);
        $otherUser = User::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'site_id'    => $otherSite->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $this->actingAs($otherUser)
            ->getJson("/participants/{$this->participant->id}/documents")
            ->assertForbidden();
    }

    // ── Auth guard ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_list_documents(): void
    {
        $this->getJson("/participants/{$this->participant->id}/documents")
            ->assertUnauthorized();
    }
}
