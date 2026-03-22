<?php

namespace Tests\Unit;

use App\Models\ClinicalNote;
use App\Services\NoteTemplateService;
use InvalidArgumentException;
use Tests\TestCase;

class NoteTemplateServiceTest extends TestCase
{
    private NoteTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NoteTemplateService::class);
    }

    // ─── Schema resolution ────────────────────────────────────────────────────

    public function test_returns_schema_for_soap_note_type(): void
    {
        $schema = $this->service->schema('soap');

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('label', $schema);
        $this->assertArrayHasKey('sections', $schema);
    }

    public function test_soap_template_has_four_sections(): void
    {
        $schema = $this->service->schema('soap');
        $sections = $schema['sections'] ?? [];

        $this->assertCount(4, $sections);

        $keys = array_column($sections, 'key');
        $this->assertContains('subjective', $keys);
        $this->assertContains('objective', $keys);
        $this->assertContains('assessment', $keys);
        $this->assertContains('plan', $keys);
    }

    public function test_returns_schema_for_each_defined_note_type(): void
    {
        // Only iterate over types that have templates defined (8 types);
        // other types (behavioral_health, telehealth, idt_summary, incident, addendum)
        // are handled generically and intentionally omitted from the config.
        $definedTypes = array_keys($this->service->all());

        foreach ($definedTypes as $type) {
            $schema = $this->service->schema($type);
            $this->assertIsArray($schema, "Expected array schema for note type '{$type}'");
            $this->assertArrayHasKey('label', $schema, "Schema for '{$type}' missing 'label'");
        }
    }

    public function test_unknown_note_type_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->schema('made_up_type');
    }

    // ─── All templates ────────────────────────────────────────────────────────

    public function test_all_returns_keyed_array_of_all_templates(): void
    {
        $all = $this->service->all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('soap', $all);
        $this->assertArrayHasKey('progress_nursing', $all);
    }

    public function test_all_includes_at_least_eight_templates(): void
    {
        $all = $this->service->all();

        // Plan calls for 8 defined templates; generics (addendum etc.) may add more
        $this->assertGreaterThanOrEqual(8, count($all));
    }

    // ─── Dropdown percentage (≥50% structured fields) ─────────────────────────

    public function test_all_templates_have_at_least_50_percent_dropdown_fields(): void
    {
        $all = $this->service->all();

        foreach (array_keys($all) as $type) {
            $pct = $this->service->dropdownPercentage($type);
            $this->assertGreaterThanOrEqual(
                50.0,
                $pct,
                "Template '{$type}' only has {$pct}% structured (dropdown/select/checkbox) fields; minimum 50% required."
            );
        }
    }

    public function test_dropdown_percentage_returns_float(): void
    {
        $pct = $this->service->dropdownPercentage('soap');

        $this->assertIsFloat($pct);
        $this->assertGreaterThan(0.0, $pct);
        $this->assertLessThanOrEqual(100.0, $pct);
    }

    // ─── Template structure ───────────────────────────────────────────────────

    public function test_each_template_section_has_key_and_label(): void
    {
        $all = $this->service->all();

        foreach ($all as $type => $schema) {
            foreach ($schema['sections'] ?? [] as $section) {
                $this->assertArrayHasKey('key', $section, "Template '{$type}' section missing 'key'");
                $this->assertArrayHasKey('label', $section, "Template '{$type}' section missing 'label'");
            }
        }
    }

    public function test_each_template_field_has_key_type_and_label(): void
    {
        $all = $this->service->all();

        foreach ($all as $type => $schema) {
            foreach ($schema['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    $this->assertArrayHasKey('key', $field,   "Template '{$type}' field missing 'key'");
                    $this->assertArrayHasKey('type', $field,  "Template '{$type}' field missing 'type'");
                    $this->assertArrayHasKey('label', $field, "Template '{$type}' field missing 'label'");
                }
            }
        }
    }

    public function test_select_fields_have_options_array(): void
    {
        $all = $this->service->all();

        foreach ($all as $type => $schema) {
            foreach ($schema['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    if (in_array($field['type'] ?? '', ['select', 'multiselect', 'radio'], true)) {
                        $this->assertArrayHasKey(
                            'options',
                            $field,
                            "Template '{$type}' field '{$field['key']}' (type={$field['type']}) missing 'options'"
                        );
                        $this->assertIsArray($field['options']);
                        $this->assertNotEmpty($field['options']);
                    }
                }
            }
        }
    }
}
