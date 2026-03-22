<?php

namespace Tests\Unit;

use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SdrModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Participant $participant;
    private User        $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'SM',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($site->id)
            ->create();
    }

    private function createSdr(array $overrides = []): Sdr
    {
        return Sdr::create(array_merge([
            'participant_id'        => $this->participant->id,
            'tenant_id'             => $this->tenant->id,
            'requesting_user_id'    => $this->user->id,
            'requesting_department' => 'primary_care',
            'assigned_department'   => 'pharmacy',
            'request_type'          => 'lab_order',
            'description'           => 'Test SDR',
            'priority'              => 'routine',
            'status'                => 'submitted',
        ], $overrides));
    }

    // ─── due_at auto-enforcement ──────────────────────────────────────────────

    public function test_due_at_is_always_submitted_at_plus_72h(): void
    {
        // Use second-level precision: Eloquent's datetime cast truncates microseconds
        $submittedAt = Carbon::now()->subHours(10)->startOfSecond();
        $sdr = $this->createSdr(['submitted_at' => $submittedAt]);

        $expectedDue = $submittedAt->copy()->addHours(72);
        $this->assertSame(0, (int) abs($sdr->due_at->diffInSeconds($expectedDue)),
            "Expected due_at {$expectedDue} but got {$sdr->due_at}");
    }

    public function test_due_at_defaults_to_now_plus_72h_when_no_submitted_at(): void
    {
        // No submitted_at provided — boot() defaults to now()
        $before = Carbon::now()->startOfSecond();
        $sdr    = $this->createSdr();
        $after  = Carbon::now()->addSecond(); // +1s tolerance for test execution time

        $this->assertTrue(
            $sdr->due_at->between($before->copy()->addHours(72), $after->copy()->addHours(72)),
            "due_at {$sdr->due_at} not between {$before->copy()->addHours(72)} and {$after->copy()->addHours(72)}"
        );
    }

    public function test_boot_rejects_due_at_beyond_72h_window(): void
    {
        $sdr = $this->createSdr();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/72/');

        $sdr->due_at = $sdr->submitted_at->copy()->addHours(73);
        $sdr->save();
    }

    public function test_boot_allows_due_at_equal_to_submitted_at_plus_72h(): void
    {
        $sdr = $this->createSdr();

        // Explicitly setting to exactly the 72h mark — should NOT throw
        $sdr->due_at = $sdr->submitted_at->copy()->addHours(72);
        $sdr->save();

        $this->assertSame(72, (int) $sdr->submitted_at->diffInHours($sdr->due_at));
    }

    // ─── isOverdue() ─────────────────────────────────────────────────────────

    public function test_is_overdue_true_when_past_due_at_and_not_completed(): void
    {
        $sdr = Sdr::factory()->overdue()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->assertTrue($sdr->isOverdue());
    }

    public function test_is_overdue_false_for_completed_sdr(): void
    {
        $sdr = Sdr::factory()->completed()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->assertFalse($sdr->isOverdue());
    }

    public function test_is_overdue_false_when_due_at_is_in_future(): void
    {
        $sdr = $this->createSdr(['submitted_at' => Carbon::now()]);
        // due_at = now + 72h → not overdue
        $this->assertFalse($sdr->isOverdue());
    }

    // ─── hoursRemaining() ────────────────────────────────────────────────────

    public function test_hours_remaining_is_positive_for_fresh_sdr(): void
    {
        $sdr = $this->createSdr(['submitted_at' => Carbon::now()]);
        $this->assertGreaterThan(0, $sdr->hoursRemaining());
    }

    public function test_hours_remaining_is_negative_when_overdue(): void
    {
        $sdr = Sdr::factory()->overdue()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->assertLessThan(0, $sdr->hoursRemaining());
    }

    // ─── urgencyClasses() ────────────────────────────────────────────────────

    public function test_urgency_classes_returns_red_when_overdue(): void
    {
        $sdr = Sdr::factory()->overdue()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->assertStringContainsString('red', $sdr->urgencyClasses());
    }

    public function test_urgency_classes_returns_gray_for_fresh_sdr(): void
    {
        $sdr = $this->createSdr(['submitted_at' => Carbon::now()]);
        $this->assertStringContainsString('gray', $sdr->urgencyClasses());
    }

    // ─── typeLabel() ─────────────────────────────────────────────────────────

    public function test_type_label_returns_human_readable_string(): void
    {
        $sdr = $this->createSdr(['request_type' => 'lab_order']);
        $this->assertSame('Lab Order', $sdr->typeLabel());
    }

    public function test_type_label_fallback_for_unknown_type(): void
    {
        $sdr = $this->createSdr(['request_type' => 'other']);
        $this->assertSame('Other', $sdr->typeLabel());
    }
}
