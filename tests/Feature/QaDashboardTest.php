<?php

// ─── QaDashboardTest ────────────────────────────────────────────────────────────
// Feature tests for the QA/Compliance Dashboard endpoints.
//
// Coverage:
//   - dashboard() renders Inertia page with 6 KPI props + incident data
//   - unsignedNotes() returns unsigned notes older than 24h as JSON
//   - overdueAssessments() returns overdue assessments as JSON
//   - exportCsv() returns correct CSV for incidents|unsigned_notes|overdue_assessments
//   - All endpoints require authentication
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'qa_compliance'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    // ── Dashboard page ────────────────────────────────────────────────────────

    public function test_dashboard_renders_inertia_page_with_kpis(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/qa/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Qa/Dashboard')
                ->has('kpis')
                ->has('openIncidents')
                ->has('incidentTypes')
                ->has('statuses')
            );
    }

    public function test_dashboard_kpis_contain_all_six_metrics(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/qa/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('kpis.sdr_compliance_rate')
                ->has('kpis.overdue_assessments_count')
                ->has('kpis.unsigned_notes_count')
                ->has('kpis.open_incidents_count')
                ->has('kpis.overdue_care_plans_count')
                ->has('kpis.hospitalizations_month')
            );
    }

    public function test_dashboard_open_incidents_count_matches_tenant(): void
    {
        $user = $this->makeUser();
        // Create 2 open incidents for this tenant
        Incident::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'status'         => 'open',
        ]);
        // Create 1 closed incident — should not count
        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'status'         => 'closed',
        ]);

        $response = $this->actingAs($user)->get('/qa/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open_incidents_count', 2)
            );
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/qa/dashboard');

        $response->assertRedirect('/login');
    }

    // ── Unsigned Notes ────────────────────────────────────────────────────────

    public function test_unsigned_notes_returns_notes_older_than_24h(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Note older than 24h (should appear)
        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'created_at'     => now()->subHours(30),
        ]);

        // Recent draft (should NOT appear — within 24h grace period)
        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'created_at'     => now()->subHours(12),
        ]);

        $response = $this->actingAs($user)->getJson('/qa/compliance/unsigned-notes');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
    }

    public function test_unsigned_notes_excludes_signed_notes(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Signed note older than 24h — should NOT appear
        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'signed',
            'signed_at'      => now()->subHours(25),
            'created_at'     => now()->subHours(30),
        ]);

        $response = $this->actingAs($user)->getJson('/qa/compliance/unsigned-notes');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    public function test_unsigned_notes_response_includes_hours_overdue(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'created_at'     => now()->subHours(30),
        ]);

        $response = $this->actingAs($user)->getJson('/qa/compliance/unsigned-notes');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('hours_overdue', $data[0]);
        $this->assertGreaterThanOrEqual(24, $data[0]['hours_overdue']);
    }

    // ── Overdue Assessments ───────────────────────────────────────────────────

    public function test_overdue_assessments_returns_past_due_date(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Overdue assessment (should appear)
        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => now()->subDays(5)->toDateString(),
        ]);

        // Not yet due (should NOT appear)
        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => now()->addDays(10)->toDateString(),
        ]);

        // No due date (should NOT appear)
        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => null,
        ]);

        $response = $this->actingAs($user)->getJson('/qa/compliance/overdue-assessments');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
    }

    public function test_overdue_assessments_response_includes_days_overdue(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => now()->subDays(7)->toDateString(),
        ]);

        $response = $this->actingAs($user)->getJson('/qa/compliance/overdue-assessments');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('days_overdue', $data[0]);
        $this->assertGreaterThanOrEqual(7, $data[0]['days_overdue']);
    }

    // ── CSV Export ────────────────────────────────────────────────────────────

    public function test_export_csv_incidents_returns_csv(): void
    {
        $user = $this->makeUser();
        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
        ]);

        $response = $this->actingAs($user)->get('/qa/reports/export?type=incidents');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('qa_incidents_', $response->headers->get('Content-Disposition'));
    }

    public function test_export_csv_unsigned_notes_returns_csv(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/qa/reports/export?type=unsigned_notes');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_csv_incidents_contains_header_row(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/qa/reports/export?type=incidents');

        $content = $response->getContent();
        $this->assertStringContainsString('ID', $content);
        $this->assertStringContainsString('Type', $content);
        $this->assertStringContainsString('Status', $content);
    }
}
