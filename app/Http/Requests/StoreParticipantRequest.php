<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->department === 'enrollment';
    }

    public function rules(): array
    {
        return [
            'site_id'            => ['required', 'integer', 'exists:shared_sites,id'],
            'first_name'         => ['required', 'string', 'max:100'],
            'last_name'          => ['required', 'string', 'max:100'],
            'preferred_name'     => ['nullable', 'string', 'max:100'],
            'dob'                => ['required', 'date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:20'],
            'pronouns'           => ['nullable', 'string', 'max:30'],
            'ssn_last_four'      => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'medicare_id'        => ['nullable', 'string', 'max:20'],
            'medicaid_id'        => ['nullable', 'string', 'max:20'],
            'pace_contract_id'   => ['nullable', 'string', 'max:20'],
            'h_number'           => ['nullable', 'string', 'max:20'],
            'primary_language'   => ['nullable', 'string', 'max:50'],
            'interpreter_needed' => ['boolean'],
            'interpreter_language'=> ['nullable', 'string', 'max:50'],
            'enrollment_status'  => ['required', 'in:referred,intake,pending,enrolled,disenrolled,deceased'],
            'enrollment_date'    => ['nullable', 'date'],
            'nursing_facility_eligible' => ['boolean'],

            // Address (optional at creation)
            'address.street'    => ['nullable', 'string', 'max:200'],
            'address.city'      => ['nullable', 'string', 'max:100'],
            'address.state'     => ['nullable', 'string', 'size:2'],
            'address.zip'       => ['nullable', 'string', 'max:10'],
        ];
    }
}
