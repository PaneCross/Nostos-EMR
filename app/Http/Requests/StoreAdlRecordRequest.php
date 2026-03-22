<?php

// ─── StoreAdlRecordRequest ────────────────────────────────────────────────────
// Validates recording a new ADL observation.
// After save, AdlRecordObserver fires threshold breach check via AdlThresholdService.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\AdlRecord;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdlRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'adl_category'       => ['required', 'in:' . implode(',', AdlRecord::CATEGORIES)],
            'independence_level' => ['required', 'in:' . implode(',', AdlRecord::LEVELS)],
            'recorded_at'        => ['nullable', 'date', 'before_or_equal:now'],
            'assistive_device_used' => ['nullable', 'string', 'max:100'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }
}
