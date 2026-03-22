<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site   $site;
    private User   $user;

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
    }

    // ─── Inertia helper ───────────────────────────────────────────────────────

    /** Make an Inertia JSON request and return the page props array. */
    private function inertiaGet(string $url, ?User $user = null): array
    {
        // Override version() → null so the Inertia middleware skips the version check
        // ('' !== (null ?? '') evaluates to false → no 409 conflict).
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $actor = $user ?? $this->user;
        $resp  = $this->actingAs($actor)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);
        $resp->assertOk();
        return $resp->json('props');
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get('/participants')->assertRedirect('/login');
    }

    // ─── Index page ───────────────────────────────────────────────────────────

    public function test_authenticated_user_can_view_directory(): void
    {
        $this->actingAs($this->user)
            ->get('/participants')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Participants/Index'));
    }

    public function test_directory_returns_only_current_tenant_participants(): void
    {
        $ours = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTHER']);
        $other       = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $props = $this->inertiaGet('/participants');
        $ids   = array_column($props['participants']['data'], 'id');

        $this->assertContains($ours->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertCount(1, $ids, 'Should only see our tenant\'s participant');
    }

    public function test_directory_search_by_name(): void
    {
        $alice = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['first_name' => 'Alice', 'last_name' => 'Testpatient']);

        $bob = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['first_name' => 'Bob', 'last_name' => 'Testpatient']);

        $props = $this->inertiaGet('/participants?search=Alice');
        $ids   = array_column($props['participants']['data'], 'id');

        $this->assertContains($alice->id, $ids);
        $this->assertNotContains($bob->id, $ids);
    }

    public function test_directory_search_by_mrn(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $props = $this->inertiaGet("/participants?search={$participant->mrn}");
        $ids   = array_column($props['participants']['data'], 'id');

        $this->assertContains($participant->id, $ids);
    }

    public function test_directory_filters_by_status(): void
    {
        $enrolled    = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $disenrolled = Participant::factory()->disenrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();

        $props = $this->inertiaGet('/participants?status=enrolled');
        $ids   = array_column($props['participants']['data'], 'id');

        $this->assertContains($enrolled->id, $ids);
        $this->assertNotContains($disenrolled->id, $ids);
    }

    // ─── Create authorization ──────────────────────────────────────────────────

    public function test_non_enrollment_user_cannot_create_participant(): void
    {
        $this->actingAs($this->user)
            ->post('/participants', [
                'first_name'        => 'Test',
                'last_name'         => 'Testpatient',
                'dob'               => '1945-03-15',
                'gender'            => 'female',
                'enrollment_status' => 'enrolled',
            ])
            ->assertForbidden();
    }

    public function test_enrollment_user_can_create_participant(): void
    {
        $enrollmentUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'enrollment',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $this->actingAs($enrollmentUser)
            ->post('/participants', [
                'site_id'                   => $this->site->id,
                'first_name'                => 'Gladys',
                'last_name'                 => 'Testpatient',
                'dob'                       => '1945-03-15',
                'gender'                    => 'female',
                'ssn_last_four'             => '1234',
                'medicare_id'               => 'A1B2C3D4E5F',
                'medicaid_id'               => '1234567890',
                'primary_language'          => 'English',
                'interpreter_needed'        => false,
                'enrollment_status'         => 'enrolled',
                'enrollment_date'           => '2023-01-01',
                'nursing_facility_eligible' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('emr_participants', [
            'first_name' => 'Gladys',
            'last_name'  => 'Testpatient',
            'tenant_id'  => $this->tenant->id,
        ]);
    }

    // ─── canCreate prop ───────────────────────────────────────────────────────

    public function test_can_create_prop_is_false_for_non_enrollment(): void
    {
        $props = $this->inertiaGet('/participants');
        $this->assertFalse($props['canCreate']);
    }

    public function test_can_create_prop_is_true_for_enrollment(): void
    {
        $enrollmentUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'enrollment',
            'is_active'  => true,
        ]);

        $props = $this->inertiaGet('/participants', $enrollmentUser);
        $this->assertTrue($props['canCreate']);
    }
}
