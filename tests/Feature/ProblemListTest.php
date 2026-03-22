<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemListTest extends TestCase
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

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_add_problem_with_valid_icd10(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/problems", [
                'icd10_code'          => 'I10',
                'icd10_description'   => 'Essential (primary) hypertension',
                'status'              => 'active',
                'onset_date'          => '2020-01-15',
                'is_primary_diagnosis' => true,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_problems', [
            'participant_id'       => $this->participant->id,
            'icd10_code'           => 'I10',
            'status'               => 'active',
            'is_primary_diagnosis' => true,
        ]);
    }

    public function test_add_problem_requires_icd10_code(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/problems", [
                'icd10_description' => 'Hypertension',
                'status'            => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['icd10_code']);
    }

    public function test_add_problem_requires_icd10_description(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/problems", [
                'icd10_code' => 'I10',
                'status'     => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['icd10_description']);
    }

    public function test_add_problem_requires_valid_status(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/problems", [
                'icd10_code'         => 'I10',
                'icd10_description'  => 'Hypertension',
                'status'      => 'not_a_real_status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_update_problem_status_to_resolved(): void
    {
        $problem = Problem::factory()
            ->active()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['added_by_user_id' => $this->user->id]);

        $resolvedDate = now()->format('Y-m-d');
        $response = $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/problems/{$problem->id}", [
                'icd10_code'         => $problem->icd10_code,
                'icd10_description'  => $problem->icd10_description,
                'status'        => 'resolved',
                'resolved_date' => $resolvedDate,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('emr_problems', [
            'id'            => $problem->id,
            'status'        => 'resolved',
            'resolved_date' => $resolvedDate,
        ]);
    }

    public function test_update_problem_sets_primary_diagnosis(): void
    {
        $problem = Problem::factory()
            ->active()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'added_by_user_id'     => $this->user->id,
                'is_primary_diagnosis' => false,
            ]);

        $this->actingAs($this->user)
            ->putJson("/participants/{$this->participant->id}/problems/{$problem->id}", [
                'icd10_code'          => $problem->icd10_code,
                'icd10_description'   => $problem->icd10_description,
                'status'               => 'active',
                'is_primary_diagnosis' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_problems', [
            'id'                   => $problem->id,
            'is_primary_diagnosis' => true,
        ]);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_problems_grouped_by_status(): void
    {
        Problem::factory()->active()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['added_by_user_id' => $this->user->id]);
        Problem::factory()->chronic()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['added_by_user_id' => $this->user->id]);
        Problem::factory()->resolved()->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['added_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/problems");

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('chronic', $data);
        $this->assertArrayHasKey('resolved', $data);
        $this->assertCount(1, $data['active']);
        $this->assertCount(1, $data['chronic']);
        $this->assertCount(1, $data['resolved']);
    }

    // ─── ICD-10 search ────────────────────────────────────────────────────────

    public function test_icd10_search_returns_matching_codes(): void
    {
        // Seed a couple of ICD-10 entries directly
        \Illuminate\Support\Facades\DB::table('emr_icd10_lookup')->insert([
            ['code' => 'I10',   'description' => 'Essential (primary) hypertension', 'category' => 'Cardiovascular', 'created_at' => now()],
            ['code' => 'I50.9', 'description' => 'Heart failure, unspecified',       'category' => 'Cardiovascular', 'created_at' => now()],
            ['code' => 'E11.9', 'description' => 'Type 2 diabetes mellitus',         'category' => 'Endocrine',      'created_at' => now()],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/icd10/search?q=hypertension');

        $response->assertOk();
        $results = $response->json();
        $this->assertNotEmpty($results);
        $this->assertTrue(collect($results)->contains('code', 'I10'));
    }

    public function test_icd10_search_is_case_insensitive(): void
    {
        \Illuminate\Support\Facades\DB::table('emr_icd10_lookup')->insert([
            ['code' => 'I10', 'description' => 'Essential (primary) hypertension', 'category' => 'Cardiovascular', 'created_at' => now()],
        ]);

        $lower = $this->actingAs($this->user)->getJson('/icd10/search?q=HYPERTENSION');
        $lower->assertOk();
        $this->assertNotEmpty($lower->json());
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_problems_scoped_to_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/problems")
            ->assertForbidden();
    }
}
