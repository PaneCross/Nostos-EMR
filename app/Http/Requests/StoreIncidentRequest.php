<?php

// ─── StoreIncidentRequest ──────────────────────────────────────────────────────
// Validates a new incident report.
// Any authenticated user may report an incident (no dept restriction on creation).
// rca_required is NOT in the rules — it is auto-set by IncidentService.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'participant_id'           => ['required', 'integer', 'exists:emr_participants,id'],
            'incident_type'            => ['required', 'string', 'in:' . implode(',', Incident::TYPES)],
            'occurred_at'              => ['required', 'date'],
            'location_of_incident'     => ['nullable', 'string', 'max:200'],
            'description'              => ['required', 'string', 'min:10', 'max:10000'],
            'immediate_actions_taken'  => ['nullable', 'string', 'max:5000'],
            'injuries_sustained'       => ['required', 'boolean'],
            'injury_description'       => ['nullable', 'string', 'required_if:injuries_sustained,true', 'max:2000'],
            'witnesses'                => ['nullable', 'array'],
            'witnesses.*.name'         => ['required_with:witnesses', 'string', 'max:100'],
            'witnesses.*.contact'      => ['nullable', 'string', 'max:100'],
            'cms_reportable'           => ['boolean'],
        ];
    }
}
