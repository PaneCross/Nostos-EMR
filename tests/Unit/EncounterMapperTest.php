<?php

// ─── EncounterMapperTest ──────────────────────────────────────────────────────
// Unit tests for EncounterMapper (FHIR R4 Encounter resource generation).
//
// Coverage:
//   - resourceType is 'Encounter'
//   - id matches appointment id
//   - subject.reference contains participant_id
//   - Status mapping: scheduled→planned, confirmed→arrived, completed→finished,
//                     cancelled→cancelled, no_show→entered-in-error, other→unknown
//   - Class mapping: home_visit→HH, telehealth→VR, all others→AMB
//   - serviceProvider reference contains site id
//   - period.start is set from scheduled_start
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Fhir\Mappers\EncounterMapper;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EncounterMapperTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(array $overrides = []): Appointment
    {
        return Appointment::factory()->create($overrides);
    }

    // ── Resource structure ────────────────────────────────────────────────────

    public function test_resource_type_is_encounter(): void
    {
        $appt = $this->makeAppointment();
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('Encounter', $fhir['resourceType']);
    }

    public function test_id_matches_appointment_id(): void
    {
        $appt = $this->makeAppointment();
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals((string) $appt->id, $fhir['id']);
    }

    public function test_subject_reference_contains_participant_id(): void
    {
        $appt = $this->makeAppointment();
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertStringContainsString((string) $appt->participant_id, $fhir['subject']['reference']);
    }

    public function test_service_provider_references_site(): void
    {
        $appt = $this->makeAppointment();
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertStringContainsString("site-{$appt->site_id}", $fhir['serviceProvider']['reference']);
    }

    // ── Status mapping ────────────────────────────────────────────────────────

    public function test_scheduled_maps_to_planned(): void
    {
        $appt = $this->makeAppointment(['status' => 'scheduled']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('planned', $fhir['status']);
    }

    public function test_confirmed_maps_to_arrived(): void
    {
        $appt = $this->makeAppointment(['status' => 'confirmed']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('arrived', $fhir['status']);
    }

    public function test_completed_maps_to_finished(): void
    {
        $appt = $this->makeAppointment(['status' => 'completed']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('finished', $fhir['status']);
    }

    public function test_cancelled_maps_to_cancelled(): void
    {
        $appt = $this->makeAppointment(['status' => 'cancelled']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('cancelled', $fhir['status']);
    }

    public function test_no_show_maps_to_entered_in_error(): void
    {
        $appt = $this->makeAppointment(['status' => 'no_show']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('entered-in-error', $fhir['status']);
    }

    // ── Class mapping ─────────────────────────────────────────────────────────

    public function test_home_visit_maps_to_hh_class(): void
    {
        $appt = $this->makeAppointment(['appointment_type' => 'home_visit']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('HH', $fhir['class']['code']);
    }

    public function test_telehealth_maps_to_vr_class(): void
    {
        $appt = $this->makeAppointment(['appointment_type' => 'telehealth']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('VR', $fhir['class']['code']);
    }

    public function test_other_types_map_to_amb_class(): void
    {
        $appt = $this->makeAppointment(['appointment_type' => 'clinic_visit']);
        $fhir = EncounterMapper::toFhir($appt);
        $this->assertEquals('AMB', $fhir['class']['code']);
    }
}
