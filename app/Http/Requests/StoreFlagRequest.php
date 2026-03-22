<?php

namespace App\Http\Requests;

use App\Models\ParticipantFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'flag_type'   => ['required', Rule::in(array_column(
                array_map(fn ($t) => ['v' => $t], [
                    'wheelchair', 'stretcher', 'oxygen', 'behavioral',
                    'fall_risk', 'wandering_risk', 'isolation', 'dnr',
                    'weight_bearing_restriction', 'dietary_restriction',
                    'elopement_risk', 'hospice', 'other',
                ]), 'v'
            ))],
            'description' => ['nullable', 'string', 'max:500'],
            'severity'    => ['required', 'in:low,medium,high,critical'],
        ];
    }
}
