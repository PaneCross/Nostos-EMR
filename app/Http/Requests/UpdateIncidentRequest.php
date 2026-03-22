<?php

// ─── UpdateIncidentRequest ────────────────────────────────────────────────────
// Validates updates to an existing incident. QA Admin only.
// All fields are optional (partial update). Status changes go through
// the dedicated /status endpoint in IncidentController.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only QA compliance or IT admin may edit incident records
        return in_array($this->user()?->department, ['qa_compliance', 'it_admin'], true);
    }

    public function rules(): array
    {
        return [
            'location_of_incident'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'description'             => ['sometimes', 'string', 'min:10', 'max:10000'],
            'immediate_actions_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'injuries_sustained'      => ['sometimes', 'boolean'],
            'injury_description'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'witnesses'               => ['sometimes', 'nullable', 'array'],
            'cms_reportable'          => ['sometimes', 'boolean'],
        ];
    }

    protected function failedAuthorization(): never
    {
        abort(403, 'Only QA Compliance or IT Admin may edit incident records.');
    }
}
