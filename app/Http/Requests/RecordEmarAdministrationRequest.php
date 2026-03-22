<?php

namespace App\Http\Requests;

use App\Models\EmarRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordEmarAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'status'           => ['required', Rule::in(['given', 'refused', 'held', 'not_available', 'missed'])],
            'administered_at'  => ['nullable', 'date', 'required_if:status,given'],
            'dose_given'       => ['nullable', 'string', 'max:50'],
            'route_given'      => ['nullable', 'string', 'max:50'],
            'reason_not_given' => ['nullable', 'string', 'max:500',
                                   Rule::requiredIf(fn () => in_array(
                                       $this->input('status'),
                                       ['refused', 'held', 'not_available', 'missed']
                                   ))],
            'witness_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
