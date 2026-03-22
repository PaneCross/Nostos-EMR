<?php

namespace Tests\Unit;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\MrnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantMrnTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site   $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'PACE',
        ]);
    }

    // ─── Format ───────────────────────────────────────────────────────────────

    public function test_mrn_is_auto_generated_on_creation(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $this->assertNotNull($participant->mrn);
        $this->assertNotEmpty($participant->mrn);
    }

    public function test_mrn_follows_prefix_dash_sequence_format(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        // Format: PREFIX-NNNNN (e.g. PACE-00001)
        $this->assertMatchesRegularExpression('/^[A-Z]{1,10}-\d{5}$/', $participant->mrn);
    }

    public function test_mrn_uses_site_mrn_prefix(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $this->assertStringStartsWith('PACE-', $participant->mrn);
    }

    public function test_mrn_sequence_starts_at_00001(): void
    {
        $first = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $this->assertEquals('PACE-00001', $first->mrn);
    }

    public function test_mrn_sequence_increments_correctly(): void
    {
        $first  = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $second = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $third  = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();

        $this->assertEquals('PACE-00001', $first->mrn);
        $this->assertEquals('PACE-00002', $second->mrn);
        $this->assertEquals('PACE-00003', $third->mrn);
    }

    public function test_mrn_sequences_are_per_site(): void
    {
        $siteB = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'WEST',
        ]);

        $fromA = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $fromB = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($siteB->id)->create();

        $this->assertEquals('PACE-00001', $fromA->mrn);
        $this->assertEquals('WEST-00001', $fromB->mrn);
    }

    public function test_mrn_not_overwritten_if_provided(): void
    {
        // If MRN is pre-set (e.g., migration from legacy system), it should not be overwritten
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['mrn' => 'LEGACY-99999']);

        $this->assertEquals('LEGACY-99999', $participant->mrn);
    }

    // ─── MrnService derive prefix fallback ────────────────────────────────────

    public function test_mrn_service_derives_prefix_from_site_name_when_prefix_null(): void
    {
        $siteWithoutPrefix = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Sunrise PACE Eastside',
            'mrn_prefix' => null,
        ]);

        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($siteWithoutPrefix->id)
            ->create();

        // MrnService::derivePrefix splits on space and uses last word uppercased, truncated to 6
        // 'Eastside' → 'EASTSI'
        $this->assertStringStartsWith('EASTSI-', $participant->mrn);
    }

    // ─── No duplicates ────────────────────────────────────────────────────────

    public function test_mrns_are_unique_across_multiple_participants(): void
    {
        $count = 10;
        $mrns  = [];

        for ($i = 0; $i < $count; $i++) {
            $p      = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
            $mrns[] = $p->mrn;
        }

        $this->assertCount($count, array_unique($mrns), 'All MRNs should be unique');
    }

    public function test_deleted_mrn_sequence_number_not_reused(): void
    {
        $first  = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $second = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();

        $this->assertEquals('PACE-00001', $first->mrn);
        $this->assertEquals('PACE-00002', $second->mrn);

        // Soft-delete the first participant
        $first->delete();

        // Next participant should get 00003, not 00001 (withTrashed() count prevents reuse)
        $third = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->assertEquals('PACE-00003', $third->mrn);
    }
}
