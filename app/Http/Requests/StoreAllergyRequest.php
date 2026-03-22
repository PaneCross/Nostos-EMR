<?php

// ─── StoreAllergyRequest ──────────────────────────────────────────────────────
// Validates adding or updating an allergy / dietary restriction record.
// life_threatening severity is validated here but the banner-display logic
// lives in ParticipantController::show() which counts active life_threatening records.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Allergy;
use Illuminate\Foundation\Http\FormRequest;

class StoreAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'allergy_type'        => ['required', 'in:' . implode(',', Allergy::ALLERGY_TYPES)],
            'allergen_name'       => ['required', 'string', 'max:150'],
            'reaction_description'=> ['nullable', 'string', 'max:1000'],
            'severity'            => ['required', 'in:' . implode(',', Allergy::SEVERITIES)],
            'onset_date'          => ['nullable', 'date', 'before_or_equal:today'],
            'is_active'           => ['boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
