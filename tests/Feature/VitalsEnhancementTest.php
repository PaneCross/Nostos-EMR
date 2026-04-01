<?php

// ─── VitalsEnhancementTest (W4-4) ─────────────────────────────────────────────
// Covers BMI computed attribute and blood_glucose_timing field.
// QW-01: BMI auto-calculated from weight_lbs + height_in.
// QW-02: Blood glucose timing context stored with reading.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VitalsEnhancementTest extends TestCase
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
            'mrn_prefix' => 'VEN',
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

    // ─── BMI computed attribute ───────────────────────────────────────────────

    public function test_bmi_computed_from_weight_and_height(): void
    {
        // 150 lbs at 65 in → ~24.96 → rounds to 25.0
        $vital = Vital::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'weight_lbs'     => 150,
            'height_in'      => 65,
        ]);

        $this->assertNotNull($vital->bmi);
        $this->assertEqualsWithDelta(25.0, $vital->bmi, 0.2);
    }

    public function test_bmi_null_when_height_missing(): void
    {
        $vital = Vital::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'weight_lbs'     => 150,
            'height_in'      => null,
        ]);

        $this->assertNull($vital->bmi);
    }

    public function test_bmi_null_when_weight_missing(): void
    {
        $vital = Vital::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'weight_lbs'     => null,
            'height_in'      => 65,
        ]);

        $this->assertNull($vital->bmi);
    }

    public function test_bmi_included_in_api_response(): void
    {
        Vital::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'weight_lbs'     => 180,
            'height_in'      => 70,
        ]);

        $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/vitals")
            ->assertOk()
            ->assertJsonPath('0.bmi', fn ($v) => $v !== null);
    }

    // ─── Blood glucose timing ─────────────────────────────────────────────────

    public function test_blood_glucose_timing_saved_with_vital(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'blood_glucose'        => 120,
                'blood_glucose_timing' => 'fasting',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_vitals', [
            'participant_id'       => $this->participant->id,
            'blood_glucose'        => 120,
            'blood_glucose_timing' => 'fasting',
        ]);
    }

    public function test_invalid_glucose_timing_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'blood_glucose'        => 120,
                'blood_glucose_timing' => 'not_valid',
            ])
            ->assertUnprocessable();
    }

    public function test_glucose_timing_nullable(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/vitals", [
                'blood_glucose' => 95,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_vitals', [
            'participant_id'       => $this->participant->id,
            'blood_glucose'        => 95,
            'blood_glucose_timing' => null,
        ]);
    }
}
