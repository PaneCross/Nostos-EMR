<?php

namespace Tests\Feature;

use App\Models\EhiExport;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EhiExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $itAdmin;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'EHI',
        ]);
        $this->itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Request export ───────────────────────────────────────────────────────

    public function test_it_admin_can_request_ehi_export(): void
    {
        $response = $this->actingAs($this->itAdmin)
            ->postJson("/participants/{$this->participant->id}/ehi-export");

        $response->assertStatus(202)
            ->assertJsonStructure(['export_id', 'status', 'download_url', 'expires_at']);

        $this->assertDatabaseHas('emr_ehi_exports', [
            'participant_id'       => $this->participant->id,
            'tenant_id'            => $this->tenant->id,
            'requested_by_user_id' => $this->itAdmin->id,
            'status'               => 'ready',
        ]);
    }

    public function test_enrollment_admin_can_request_ehi_export(): void
    {
        $enrollmentAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'enrollment',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $this->actingAs($enrollmentAdmin)
            ->postJson("/participants/{$this->participant->id}/ehi-export")
            ->assertStatus(202);
    }

    public function test_standard_user_cannot_request_ehi_export(): void
    {
        $standard = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $this->actingAs($standard)
            ->postJson("/participants/{$this->participant->id}/ehi-export")
            ->assertForbidden();
    }

    public function test_cross_tenant_export_request_rejected(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XEH']);
        $otherPt = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->itAdmin)
            ->postJson("/participants/{$otherPt->id}/ehi-export")
            ->assertForbidden();
    }

    // ─── Download ─────────────────────────────────────────────────────────────

    public function test_download_with_valid_token_returns_zip(): void
    {
        // Create a fake ZIP file
        $token    = bin2hex(random_bytes(32));
        $filePath = "ehi_exports/test_{$token}.zip";
        Storage::put($filePath, 'fake-zip-content');

        $export = EhiExport::factory()->ready()->create([
            'participant_id'       => $this->participant->id,
            'tenant_id'            => $this->tenant->id,
            'requested_by_user_id' => $this->itAdmin->id,
            'token'                => $token,
            'file_path'            => $filePath,
        ]);

        $response = $this->actingAs($this->itAdmin)
            ->get("/participants/{$this->participant->id}/ehi-export/{$token}/download");

        $response->assertOk();
        $this->assertNotNull($export->fresh()->downloaded_at);
    }

    public function test_download_with_expired_export_returns_410(): void
    {
        $token    = bin2hex(random_bytes(32));
        $filePath = "ehi_exports/expired_{$token}.zip";

        $export = EhiExport::factory()->expired()->create([
            'participant_id'       => $this->participant->id,
            'tenant_id'            => $this->tenant->id,
            'requested_by_user_id' => $this->itAdmin->id,
            'token'                => $token,
            'file_path'            => $filePath,
        ]);

        $this->actingAs($this->itAdmin)
            ->get("/participants/{$this->participant->id}/ehi-export/{$token}/download")
            ->assertStatus(410);
    }

    public function test_download_with_invalid_token_returns_404(): void
    {
        $this->actingAs($this->itAdmin)
            ->get("/participants/{$this->participant->id}/ehi-export/invalid-token-here/download")
            ->assertNotFound();
    }

    // ─── Audit log ────────────────────────────────────────────────────────────

    public function test_request_writes_audit_log(): void
    {
        $this->actingAs($this->itAdmin)
            ->postJson("/participants/{$this->participant->id}/ehi-export");

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'ehi_export_generated',
            'resource_type' => 'participant',
            'resource_id'   => $this->participant->id,
        ]);
    }
}
