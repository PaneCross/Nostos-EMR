<?php

// ─── NoteTemplateService ──────────────────────────────────────────────────────
// Returns structured field schemas for each clinical note type.
// Schemas are defined in config/emr_note_templates.php.
//
// Each template schema ensures ≥50% of its fields are dropdown/select/checkbox
// types (not free text) per QA requirements.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use InvalidArgumentException;

class NoteTemplateService
{
    /**
     * Return the full schema for a specific note type.
     *
     * @throws InvalidArgumentException if the note type has no template defined
     */
    public function schema(string $noteType): array
    {
        $templates = config('emr_note_templates');

        if (! isset($templates[$noteType])) {
            throw new InvalidArgumentException("No template defined for note type: {$noteType}");
        }

        return $templates[$noteType];
    }

    /**
     * Return all templates keyed by note type.
     */
    public function all(): array
    {
        return config('emr_note_templates', []);
    }

    /**
     * Calculate the percentage of fields in a template that are dropdown/select/checkbox types.
     * Used to enforce the ≥50% structured-field rule (QA requirement).
     */
    public function dropdownPercentage(string $noteType): float
    {
        $schema = $this->schema($noteType);
        $total = 0;
        $structured = 0;

        $structuredTypes = ['select', 'multiselect', 'checkbox', 'radio', 'number', 'date'];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $total++;
                if (in_array($field['type'] ?? '', $structuredTypes, true)) {
                    $structured++;
                }
            }
        }

        if ($total === 0) {
            return 0.0;
        }

        return round(($structured / $total) * 100, 1);
    }
}
