<?php

namespace App\Http\Requests;

use App\Models\Medication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated clinician can add medications (dept check in controller)
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'drug_name'                    => ['required', 'string', 'max:200'],
            'rxnorm_code'                  => ['nullable', 'string', 'max:20'],
            'dose'                         => ['nullable', 'numeric', 'min:0'],
            'dose_unit'                    => ['nullable', Rule::in(Medication::DOSE_UNITS)],
            'route'                        => ['nullable', Rule::in(Medication::ROUTES)],
            'frequency'                    => ['nullable', Rule::in(Medication::FREQUENCIES)],
            'is_prn'                       => ['boolean'],
            'prn_indication'               => ['nullable', 'string', 'max:300', 'required_if:is_prn,true'],
            'prescribing_provider_user_id' => ['nullable', 'integer', 'exists:shared_users,id'],
            'prescribed_date'              => ['nullable', 'date'],
            'start_date'                   => ['required', 'date'],
            'end_date'                     => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_controlled'                => ['boolean'],
            'controlled_schedule'          => ['nullable', Rule::in(['II', 'III', 'IV', 'V'])],
            'refills_remaining'            => ['nullable', 'integer', 'min:0'],
            'pharmacy_notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
