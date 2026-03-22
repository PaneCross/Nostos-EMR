<?php

// ─── Edi837PBatchTest ─────────────────────────────────────────────────────────
// Feature tests for the Phase 9B EdiBatchController.
//
// Coverage:
//   - test_finance_user_can_list_edi_batches
//   - test_batch_list_never_includes_file_content
//   - test_finance_user_can_download_batch
//   - test_download_returns_edi_x12_content_type
//   - test_can_upload_277ca_acknowledgement
//   - test_277ca_sets_batch_status_to_acknowledged
//   - test_non_finance_user_cannot_access_batches
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\EdiBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Edi837PBatchTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    private function makeBatch(User $user, array $attrs = []): EdiBatch
    {
        return EdiBatch::factory()->create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'status'        => 'draft',
            'file_content'  => "ISA*00*...\nGE*1*1\nIEA*1*000000001\n",
            'record_count'  => 2,
            'total_charge_amount' => 350.00,
        ], $attrs));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_finance_user_can_list_edi_batches(): void
    {
        $user = $this->financeUser();
        $this->makeBatch($user);
        $this->makeBatch($user);

        $this->actingAs($user)
            ->getJson('/billing/batches')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_batch_list_never_includes_file_content(): void
    {
        $user = $this->financeUser();
        $this->makeBatch($user);

        $resp = $this->actingAs($user)
            ->getJson('/billing/batches')
            ->assertOk();

        foreach ($resp->json('data') as $batch) {
            $this->assertArrayNotHasKey('file_content', $batch);
        }
    }

    public function test_finance_user_can_download_batch(): void
    {
        $user  = $this->financeUser();
        $batch = $this->makeBatch($user);

        $this->actingAs($user)
            ->get("/billing/batches/{$batch->id}/download")
            ->assertOk();
    }

    public function test_download_returns_edi_x12_content_type(): void
    {
        $user  = $this->financeUser();
        $batch = $this->makeBatch($user);

        $resp = $this->actingAs($user)
            ->get("/billing/batches/{$batch->id}/download");

        $this->assertStringContainsString('edi', strtolower($resp->headers->get('Content-Type') ?? ''));
    }

    public function test_can_upload_277ca_acknowledgement(): void
    {
        $user  = $this->financeUser();
        $batch = $this->makeBatch($user, ['status' => 'submitted']);

        $ack277 = "ISA*00*          *00*          *ZZ*CMSEDS*ZZ*TEST*250101*1200*^*00501*000000001*0*P*:~"
                . "GS*FA*CMSEDS*TEST*20250101*1200*1*X*005010X231A1~"
                . "ST*277*0001*005010X231A1~"
                . "BHT*0085*08*{$batch->id}*20250101*1200~"
                . "STC*A1:20*20250101**350.00~"
                . "SE*5*0001~GE*1*1~IEA*1*000000001~";

        $this->actingAs($user)
            ->postJson("/billing/batches/{$batch->id}/acknowledge", [
                'edi_content' => $ack277,
            ])
            ->assertOk()
            ->assertJsonStructure(['status']);
    }

    public function test_277ca_sets_batch_status_to_acknowledged(): void
    {
        $user  = $this->financeUser();
        $batch = $this->makeBatch($user, ['status' => 'submitted']);

        $ack277 = "ISA*00*          *00*          *ZZ*CMSEDS*ZZ*TEST*250101*1200*^*00501*000000001*0*P*:~"
                . "GS*FA*CMSEDS*TEST*20250101*1200*1*X*005010X231A1~"
                . "ST*277*0001*005010X231A1~BHT*0085*08*REF*20250101*1200~"
                . "STC*A1:20*20250101**350.00~SE*5*0001~GE*1*1~IEA*1*000000001~";

        $this->actingAs($user)
            ->postJson("/billing/batches/{$batch->id}/acknowledge", ['edi_content' => $ack277])
            ->assertOk();

        $this->assertContains(
            $batch->fresh()->status,
            ['acknowledged', 'partially_accepted', 'rejected']
        );
    }

    public function test_non_finance_user_cannot_access_batches(): void
    {
        $nurse = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($nurse)
            ->getJson('/billing/batches')
            ->assertForbidden();
    }
}
