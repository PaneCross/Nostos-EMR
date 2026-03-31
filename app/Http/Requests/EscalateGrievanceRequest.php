<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EscalateGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'escalation_reason'    => ['required', 'string', 'min:10'],
            // Optional: specific staff member to assign the escalation to.
            // Must be a valid user in shared_users. Validated in controller for tenant isolation.
            'escalated_to_user_id' => ['nullable', 'integer'],
        ];
    }
}
