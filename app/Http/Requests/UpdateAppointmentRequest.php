<?php

// ─── UpdateAppointmentRequest ─────────────────────────────────────────────────
// All fields are optional (PATCH-friendly). Same constraints as store.
// cancellation_reason is required_if:status,cancelled.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'appointment_type'    => ['sometimes', Rule::in(Appointment::APPOINTMENT_TYPES)],
            'scheduled_start'     => ['sometimes', 'date'],
            'scheduled_end'       => ['sometimes', 'date', 'after:scheduled_start'],
            'status'              => ['sometimes', Rule::in(Appointment::STATUSES)],
            'cancellation_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:1000'],
            'provider_user_id'    => ['nullable', 'integer', 'exists:shared_users,id'],
            'location_id'         => ['nullable', 'integer', 'exists:emr_locations,id'],
            'transport_required'  => ['boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
