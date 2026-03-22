<?php

// ─── StoreClinicalNoteRequest ─────────────────────────────────────────────────
// Validates creating a new clinical note (always starts as a draft).
// late_entry_reason is required when is_late_entry is true.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ClinicalNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'note_type'          => ['required', 'in:' . implode(',', ClinicalNote::NOTE_TYPES)],
            'visit_type'         => ['required', 'in:in_center,home_visit,telehealth,phone'],
            'visit_date'         => ['required', 'date', 'before_or_equal:today'],
            'visit_time'         => ['nullable', 'date_format:H:i'],
            'department'         => ['required', 'string', 'max:30'],

            // SOAP fields (nullable — only populated for soap note_type)
            'subjective'         => ['nullable', 'string', 'max:10000'],
            'objective'          => ['nullable', 'string', 'max:10000'],
            'assessment'         => ['nullable', 'string', 'max:10000'],
            'plan'               => ['nullable', 'string', 'max:10000'],

            // Structured content for non-SOAP templates
            'content'            => ['nullable', 'array'],

            // Late entry compliance
            'is_late_entry'      => ['boolean'],
            'late_entry_reason'  => ['required_if:is_late_entry,true', 'nullable', 'string', 'max:500'],
        ];
    }
}
