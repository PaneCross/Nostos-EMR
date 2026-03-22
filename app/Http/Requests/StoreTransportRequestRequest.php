<?php

namespace App\Http\Requests;

use App\Models\TransportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'participant_id'        => ['required', 'integer', 'exists:emr_participants,id'],
            'trip_type'             => ['required', Rule::in(TransportRequest::TRIP_TYPES)],
            'pickup_location_id'    => ['required', 'integer', 'exists:emr_locations,id'],
            'dropoff_location_id'   => ['required', 'integer', 'exists:emr_locations,id'],
            'requested_pickup_time' => ['required', 'date'],
            'appointment_id'        => ['nullable', 'integer', 'exists:emr_appointments,id'],
            'special_instructions'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
