<?php

// ─── RemittanceBatchTest ───────────────────────────────────────────────────────
// Feature tests for W5-3 835 Remittance Batch processing (RemittanceController).
// Coverage:
//   - upload(): returns 201 with batch_id on valid 835 file
//   - upload(): rejects invalid file extension (422)
//   - upload(): rejects file missing ISA segment (422)
//   - upload(): blocks non-finance departments (403)
//   - index(): returns Inertia page with batches prop
//   - show(): returns Inertia page with batch + claimStats props
//   - show(): returns 403 for cross-tenant batch
//   - claims(): returns paginated JSON with data + meta keys
//   - claims(): supports ?status= filter
//   - unauthenticated requests redirect to /login
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RemittanceBatchTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'finance', ?int $tenantId = null): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId !== null) {
            $attrs['tenant_id'] = $tenantId;
        }
        return User::factory()->create($attrs);
    }

    /**
     * Build a minimal valid X12 835 EDI file content string.
     * The controller only checks for the presence of 'ISA' — this satisfies it.
     */
    private function validEdiContent(): string
    {
        return "ISA*00*          *00*          *ZZ*PAYER          *ZZ*PAYEE          *260101*1200*^*00501*000000001*0*P*:~\n" .
               "GS*HP*PAYER*PAYEE*20260101*1200*1*X*005010X221A1~\n" .
               "ST*835*0001~\n" .
               "BPR*I*1500.00*C*CHK************20260101~\n" .
               "TRN*1*12345678*1234567890~\n" .
               "SE*4*0001~\n" .
               "GE*1*1~\n" .
               "IEA*1*000000001~\n";
    }

    /**
     * Build a fake UploadedFile with the given content and extension.
     */
    private function makeFile(string $content, string $ext = '835'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'test_era_');
        file_put_contents($tmpPath, $content);
        return new UploadedFile($tmpPath, "test_remittance.{$ext}", 'text/plain', null, true);
    }

    private function makeBatch(User $user, array $overrides = []): RemittanceBatch
    {
        return RemittanceBatch::factory()->create(array_merge([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_upload_returns_201_with_batch_id_for_valid_835_file(): void
    {
        Queue::fake();

        $user = $this->makeUser('finance');
        $file = $this->makeFile($this->validEdiContent(), '835');

        $response = $this->actingAs($user)
            ->postJson('/finance/remittance/upload', ['file' => $file])
            ->assertCreated()
            ->assertJsonStructure(['message', 'batch_id', 'status', 'file_name']);

        $this->assertDatabaseHas('emr_remittance_batches', [
            'id'        => $response->json('batch_id'),
            'tenant_id' => $user->tenant_id,
            'status'    => 'received',
        ]);
    }

    public function test_upload_rejects_invalid_file_extension(): void
    {
        Queue::fake();

        $user = $this->makeUser('finance');
        $file = $this->makeFile($this->validEdiContent(), 'pdf');

        $this->actingAs($user)
            ->postJson('/finance/remittance/upload', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_rejects_file_missing_isa_segment(): void
    {
        Queue::fake();

        $user    = $this->makeUser('finance');
        // Content with no ISA segment — controller should reject it
        $content = "ST*835*0001~\nBPR*I*100.00*C*CHK~\nSE*2*0001~\n";
        $file    = $this->makeFile($content, '835');

        $this->actingAs($user)
            ->postJson('/finance/remittance/upload', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_returns_403_for_non_finance_department(): void
    {
        Queue::fake();

        $user = $this->makeUser('primary_care');
        $file = $this->makeFile($this->validEdiContent(), '835');

        $this->actingAs($user)
            ->postJson('/finance/remittance/upload', ['file' => $file])
            ->assertForbidden();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_inertia_page_with_batches_prop(): void
    {
        $user = $this->makeUser('finance');

        $this->makeBatch($user);
        $this->makeBatch($user);

        $this->actingAs($user)
            ->get('/finance/remittance')
            ->assertOk()
            ->assertInertia(fn ($page) =>
                $page->component('Finance/Remittance')
                     ->has('batches')
                     ->has('summary')
            );
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_inertia_page_with_batch_and_claim_stats(): void
    {
        $user  = $this->makeUser('finance');
        $batch = $this->makeBatch($user, ['status' => 'processed', 'claim_count' => 5]);

        $this->actingAs($user)
            ->get("/finance/remittance/{$batch->id}")
            ->assertOk()
            ->assertInertia(fn ($page) =>
                $page->component('Finance/Remittance')
                     ->has('batch')
                     ->has('claimStats')
                     ->where('batch.id', $batch->id)
            );
    }

    public function test_show_returns_403_for_cross_tenant_batch(): void
    {
        $user         = $this->makeUser('finance');
        $otherTenant  = Tenant::factory()->create();
        $otherUser    = $this->makeUser('finance', $otherTenant->id);
        $foreignBatch = $this->makeBatch($otherUser);

        $this->actingAs($user)
            ->get("/finance/remittance/{$foreignBatch->id}")
            ->assertForbidden();
    }

    // ── Claims ────────────────────────────────────────────────────────────────

    public function test_claims_returns_paginated_json(): void
    {
        $user  = $this->makeUser('finance');
        $batch = $this->makeBatch($user, ['status' => 'processed']);

        // Create 3 claims for this batch
        RemittanceClaim::factory()->count(3)->create([
            'remittance_batch_id' => $batch->id,
            'tenant_id'           => $user->tenant_id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/finance/remittance/{$batch->id}/claims")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_claims_supports_status_filter(): void
    {
        $user  = $this->makeUser('finance');
        $batch = $this->makeBatch($user, ['status' => 'processed']);

        RemittanceClaim::factory()->create([
            'remittance_batch_id' => $batch->id,
            'tenant_id'           => $user->tenant_id,
            'claim_status'        => 'denied',
        ]);
        RemittanceClaim::factory()->create([
            'remittance_batch_id' => $batch->id,
            'tenant_id'           => $user->tenant_id,
            'claim_status'        => 'paid_full',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/finance/remittance/{$batch->id}/claims?status=denied")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('denied', $response->json('data.0.claim_status'));
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $this->get('/finance/remittance')
            ->assertRedirect('/login');
    }
}
