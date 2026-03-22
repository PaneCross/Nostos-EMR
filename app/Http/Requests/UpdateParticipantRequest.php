<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // Field-level RBAC: what each department may update
        $dept = $this->user()->department;

        $base = [
            'preferred_name'      => ['nullable', 'string', 'max:100'],
            'primary_language'    => ['nullable', 'string', 'max:50'],
            'interpreter_needed'  => ['boolean'],
            'interpreter_language'=> ['nullable', 'string', 'max:50'],
            // Advance directive fields (42 CFR 460.96 — writable by clinical depts)
            'advance_directive_status' => ['nullable', 'in:has_directive,declined_directive,incapacitated_no_directive,unknown'],
            'advance_directive_type'   => ['nullable', 'in:dnr,polst,living_will,healthcare_proxy,combined'],
            'advance_directive_reviewed_at' => ['nullable', 'date'],
        ];

        $enrollmentFields = [
            'site_id'            => ['integer', 'exists:shared_sites,id'],
            'first_name'         => ['string', 'max:100'],
            'last_name'          => ['string', 'max:100'],
            'dob'                => ['date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:20'],
            'pronouns'           => ['nullable', 'string', 'max:30'],
            'ssn_last_four'      => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'medicare_id'        => ['nullable', 'string', 'max:20'],
            'medicaid_id'        => ['nullable', 'string', 'max:20'],
            'pace_contract_id'   => ['nullable', 'string', 'max:20'],
            'h_number'           => ['nullable', 'string', 'max:20'],
            'enrollment_status'  => ['in:referred,intake,pending,enrolled,disenrolled,deceased'],
            'enrollment_date'    => ['nullable', 'date'],
            'disenrollment_date' => ['nullable', 'date'],
            'disenrollment_reason'=> ['nullable', 'string'],
            'nursing_facility_eligible' => ['boolean'],
            'nf_certification_date'     => ['nullable', 'date'],
        ];

        return match (true) {
            in_array($dept, ['enrollment', 'it_admin']) => array_merge($base, $enrollmentFields),
            default => $base,
        };
    }
}
