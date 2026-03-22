<?php

// ─── StoreLocationRequest ─────────────────────────────────────────────────────
// Validates new location creation. Locations are managed by the Transportation
// Team — authorization is enforced in LocationController, not here.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'location_type' => ['required', Rule::in(Location::LOCATION_TYPES)],
            'name'          => ['required', 'string', 'max:150'],
            'label'         => ['nullable', 'string', 'max:100'],
            'street'        => ['nullable', 'string', 'max:200'],
            'unit'          => ['nullable', 'string', 'max:50'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'size:2'],
            'zip'           => ['nullable', 'string', 'max:10'],
            'phone'         => ['nullable', 'string', 'max:20'],
            'contact_name'  => ['nullable', 'string', 'max:150'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'is_active'     => ['boolean'],
        ];
    }
}
