<?php

// ─── StoreAppointmentRequest ──────────────────────────────────────────────────
// Validates new appointment creation.
//
// Business rules enforced here:
//   - scheduled_end must be after scheduled_start
//   - cancellation_reason is required only when status = 'cancelled'
//   - status on create can only be 'scheduled' or 'confirmed'
//     (completed/cancelled/no_show are set via dedicated actions in controller)
//
// Conflict detection (participant overlap, transport window) is handled in
// AppointmentController, not here — it requires DB queries and returns a
// structured 409 response rather than a validation error.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'appointment_type'  => ['required', Rule::in(Appointment::APPOINTMENT_TYPES)],
            'scheduled_start'   => ['required', 'date'],
            'scheduled_end'     => ['required', 'date', 'after:scheduled_start'],
            'status'            => ['sometimes', Rule::in(['scheduled', 'confirmed'])],
            'provider_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'location_id'       => ['nullable', 'integer', 'exists:emr_locations,id'],
            'transport_required'=> ['boolean'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ];
    }
}
