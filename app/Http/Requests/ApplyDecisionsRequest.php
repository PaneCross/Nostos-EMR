<?php

// ─── ApplyDecisionsRequest ────────────────────────────────────────────────────
// Validates the Step 4 payload: an array of clinician decisions where each
// entry specifies a medication and what action to take (keep/discontinue/add/modify).
// The 'modify' action requires new_dose and new_frequency.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\MedReconciliation;
use Illuminate\Foundation\Http\FormRequest;

class ApplyDecisionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decisions'                       => ['required', 'array', 'min:1'],
            'decisions.*.drug_name'           => ['required', 'string', 'max:200'],
            'decisions.*.action'              => ['required', 'string', 'in:' . implode(',', MedReconciliation::DECISION_ACTIONS)],
            'decisions.*.medication_id'       => ['nullable', 'integer'],
            'decisions.*.notes'               => ['nullable', 'string', 'max:1000'],
            // Modify-specific fields
            'decisions.*.new_dose'            => ['nullable', 'string', 'max:50'],
            'decisions.*.new_frequency'       => ['nullable', 'string', 'max:100'],
            'decisions.*.new_route'           => ['nullable', 'string', 'max:50'],
            // Prior medication data (needed when action = 'add')
            'decisions.*.prior_medication'    => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'decisions.required' => 'At least one decision is required.',
            'decisions.*.action.in' => 'Each decision must have a valid action: keep, discontinue, add, or modify.',
        ];
    }
}
