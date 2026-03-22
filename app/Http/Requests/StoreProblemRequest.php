<?php

// ─── StoreProblemRequest ──────────────────────────────────────────────────────
// Validates adding or updating a problem list entry.
// icd10_code and icd10_description are stored inline (not FK) so that historical
// records remain intact if the lookup table changes over time.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Problem;
use Illuminate\Foundation\Http\FormRequest;

class StoreProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'icd10_code'          => ['required', 'string', 'max:10'],
            'icd10_description'   => ['required', 'string', 'max:200'],
            'status'              => ['required', 'in:' . implode(',', Problem::STATUSES)],
            'onset_date'          => ['nullable', 'date', 'before_or_equal:today'],
            'resolved_date'       => ['nullable', 'date', 'before_or_equal:today'],
            'is_primary_diagnosis'=> ['boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
