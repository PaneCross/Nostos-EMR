<?php

namespace App\Http\Requests;

use App\Models\TransportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Transportation team can schedule (set scheduled_pickup_time)
            'status'                => ['sometimes', Rule::in(['scheduled', 'cancelled'])],
            'scheduled_pickup_time' => ['sometimes', 'nullable', 'date'],
            'special_instructions'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cancellation_reason'   => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
        ];
    }
}
