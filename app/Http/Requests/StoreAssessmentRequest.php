<?php

// ─── StoreAssessmentRequest ───────────────────────────────────────────────────
// Validates creating a new clinical assessment.
// responses is a required array — its structure varies by assessment_type
// and is validated downstream by NoteTemplateService if needed.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'assessment_type'   => ['required', 'in:' . implode(',', Assessment::TYPES)],
            'department'        => ['nullable', 'string', 'max:30'],
            'responses'         => ['nullable', 'array'],
            'score'             => ['nullable', 'integer', 'min:0', 'max:200'],
            'completed_at'      => ['required', 'date', 'before_or_equal:now'],
            'next_due_date'     => ['nullable', 'date', 'after:today'],
            'threshold_flags'   => ['nullable', 'array'],
        ];
    }
}
