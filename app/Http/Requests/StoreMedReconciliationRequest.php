<?php

namespace App\Http\Requests;

use App\Models\MedReconciliation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'reconciliation_type'                => ['required', Rule::in(MedReconciliation::TYPES)],
            'reconciled_at'                      => ['required', 'date'],
            'clinical_notes'                     => ['nullable', 'string', 'max:5000'],
            'has_discrepancies'                  => ['boolean'],
            // Array of medication reconciliation entries
            'reconciled_medications'             => ['required', 'array'],
            'reconciled_medications.*.medication_id'  => ['required', 'integer', 'exists:emr_medications,id'],
            'reconciled_medications.*.drug_name'      => ['required', 'string', 'max:200'],
            'reconciled_medications.*.action'         => ['required', Rule::in(['continue', 'discontinue', 'modify', 'new'])],
            'reconciled_medications.*.discrepancy_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
