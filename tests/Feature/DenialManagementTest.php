<?php

// ─── DenialManagementTest ─────────────────────────────────────────────────────
// Feature tests for W5-3 Denial Management module (DenialController).
// Coverage:
//   - index(): returns Inertia page with denials + kpis props
//   - index(): supports ?status= filter
//   - index(): supports ?overdue=1 filter (past 120-day appeal deadline)
//   - show(): returns JSON detail with denial fields
//   - update(): saves appeal_notes on open denial
//   - update(): returns 409 for terminal denial
//   - appeal(): transitions open to appealing with appeal_submitted_date set
//   - appeal(): returns 409 if denial is not open
//   - writeOff(): writes off denial with resolution_notes
//   - writeOff(): returns 403 for it_admin (finance dept only)
//   - unauthenticated requests redirect to /login
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\DenialRecord;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DenialManagementTest extends TestCase
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

    private function makeBatch(User $user): RemittanceBatch
    {
        return RemittanceBatch::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
            'status'             => 'processed',
        ]);
    }

    private function makeClaim(RemittanceBatch $batch, User $user): RemittanceClaim
    {
        return RemittanceClaim::factory()->create([
            'remittance_batch_id' => $batch->id,
            'tenant_id'           => $user->tenant_id,
            'claim_status'        => 'denied',
        ]);
    }

    private function makeDenial(User $user, array $overrides = []): DenialRecord
    {
        $batch = $this->makeBatch($user);
        $claim = $this->makeClaim($batch, $user);

        return DenialRecord::factory()->create(array_merge([
            'tenant_id'           => $user->tenant_id,
            'remittance_claim_id' => $claim->id,
            'status'              => 'open',
            'denial_date'         => now()->subDays(10)->toDateString(),
            'appeal_deadline'     => now()->addDays(110)->toDateString(),
            'denied_amount'       => 500.00,
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_inertia_page_with_denials_and_kpis(): void
    {
        $user = $this->makeUser('finance');
        $this->makeDenial($user);

        $this->actingAs($user)
            ->get('/finance/denials')
            ->assertOk()
            ->assertInertia(fn ($page) =>
                $page->component('Finance/Denials')
                     ->has('denials')
                     ->has('kpis')
                     ->has('filters')
            );
    }

    public function test_index_supports_status_filter(): void
    {
        $user = $this->makeUser('finance');

        $this->makeDenial($user, ['status' => 'open']);
        $this->makeDenial($user, ['status' => 'appealing']);

        $response = $this->actingAs($user)
            ->get('/finance/denials?status=open')
            ->assertOk();

        // The paginated result should only contain 'open' denials
        $denials = $response->original->getData()['page']['props']['denials']['data'] ?? [];
        foreach ($denials as $denial) {
            $this->assertEquals('open', $denial['status']);
        }
    }

    public function test_index_supports_overdue_filter(): void
    {
        $user = $this->makeUser('finance');

        // Overdue denial: appeal deadline in the past, still open
        $this->makeDenial($user, [
            'status'          => 'open',
            'denial_date'     => now()->subDays(130)->toDateString(),
            'appeal_deadline' => now()->subDays(10)->toDateString(),
            'resolution_date' => null,
        ]);

        // Non-overdue denial (future deadline)
        $this->makeDenial($user, [
            'status'          => 'open',
            'denial_date'     => now()->subDays(5)->toDateString(),
            'appeal_deadline' => now()->addDays(115)->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->get('/finance/denials?overdue=1')
            ->assertOk();

        $denials = $response->original->getData()['page']['props']['denials']['data'] ?? [];
        $this->assertCount(1, $denials);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_json_detail_for_denial(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, [
            'denial_category' => 'authorization',
            'denied_amount'   => 750.00,
        ]);

        $this->actingAs($user)
            ->getJson("/finance/denials/{$denial->id}")
            ->assertOk()
            ->assertJsonStructure([
                'id', 'status', 'denial_category', 'denied_amount',
                'appeal_deadline', 'days_until_deadline', 'is_overdue',
                'adjustments',
            ])
            ->assertJsonPath('denial_category', 'authorization')
            ->assertJsonPath('denied_amount', 750);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_saves_appeal_notes_on_open_denial(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, ['status' => 'open']);

        $this->actingAs($user)
            ->patchJson("/finance/denials/{$denial->id}", [
                'appeal_notes' => 'Supporting documentation attached.',
            ])
            ->assertOk()
            ->assertJsonPath('denial.id', $denial->id);

        $this->assertDatabaseHas('emr_denial_records', [
            'id'           => $denial->id,
            'appeal_notes' => 'Supporting documentation attached.',
        ]);
    }

    public function test_update_returns_409_for_terminal_denial(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, [
            'status'          => 'written_off',
            'resolution_date' => now()->toDateString(),
            'resolution_notes'=> 'Written off.',
        ]);

        $this->actingAs($user)
            ->patchJson("/finance/denials/{$denial->id}", [
                'appeal_notes' => 'Trying to update a terminal denial.',
            ])
            ->assertStatus(409);
    }

    // ── Appeal ────────────────────────────────────────────────────────────────

    public function test_appeal_transitions_open_denial_to_appealing(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, ['status' => 'open']);

        $this->actingAs($user)
            ->postJson("/finance/denials/{$denial->id}/appeal", [
                'appeal_notes' => 'Filing formal appeal.',
            ])
            ->assertOk()
            ->assertJsonPath('denial.status', 'appealing');

        $this->assertDatabaseHas('emr_denial_records', [
            'id'                   => $denial->id,
            'status'               => 'appealing',
            'appeal_submitted_date'=> now()->toDateString(),
        ]);
    }

    public function test_appeal_returns_409_if_denial_is_not_open(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, ['status' => 'appealing']);

        $this->actingAs($user)
            ->postJson("/finance/denials/{$denial->id}/appeal")
            ->assertStatus(409);
    }

    // ── Write Off ─────────────────────────────────────────────────────────────

    public function test_finance_user_can_write_off_denial(): void
    {
        $user   = $this->makeUser('finance');
        $denial = $this->makeDenial($user, ['status' => 'open']);

        $this->actingAs($user)
            ->postJson("/finance/denials/{$denial->id}/write-off", [
                'resolution_notes' => 'Appeal deadline passed, amount not economical to pursue.',
            ])
            ->assertOk()
            ->assertJsonPath('denial.status', 'written_off');

        $this->assertDatabaseHas('emr_denial_records', [
            'id'                   => $denial->id,
            'status'               => 'written_off',
            'written_off_by_user_id' => $user->id,
        ]);
    }

    public function test_write_off_returns_403_for_it_admin(): void
    {
        // Write-off is finance-only — it_admin cannot make this revenue cycle decision
        $user   = $this->makeUser('it_admin');
        $denial = $this->makeDenial($user, ['status' => 'open']);

        $this->actingAs($user)
            ->postJson("/finance/denials/{$denial->id}/write-off", [
                'resolution_notes' => 'Attempting write-off as it_admin.',
            ])
            ->assertForbidden();
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $this->get('/finance/denials')
            ->assertRedirect('/login');
    }
}
