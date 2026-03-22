<?php

// ─── StoreVitalRequest ────────────────────────────────────────────────────────
// Validates new vital sign recordings. All measurement fields are nullable
// since not all vitals are captured at every encounter.
// Ranges are clinical bounds for the elderly PACE population.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'recorded_at'       => ['nullable', 'date', 'before_or_equal:now'],
            'bp_systolic'       => ['nullable', 'integer', 'min:40', 'max:300'],
            'bp_diastolic'      => ['nullable', 'integer', 'min:20', 'max:200'],
            'pulse'             => ['nullable', 'integer', 'min:20', 'max:300'],
            'respiratory_rate'  => ['nullable', 'integer', 'min:4', 'max:60'],
            'temperature_f'     => ['nullable', 'numeric', 'min:85', 'max:115'],
            'o2_saturation'     => ['nullable', 'integer', 'min:0', 'max:100'],
            'weight_lbs'        => ['nullable', 'numeric', 'min:40', 'max:600'],
            'height_in'         => ['nullable', 'numeric', 'min:36', 'max:96'],
            'pain_score'        => ['nullable', 'integer', 'min:0', 'max:10'],
            'blood_glucose'     => ['nullable', 'integer', 'min:20', 'max:600'],
            'position'          => ['nullable', 'in:sitting,standing,lying'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ];
    }
}
