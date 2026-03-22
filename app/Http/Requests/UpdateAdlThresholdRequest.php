<?php

// ─── UpdateAdlThresholdRequest ────────────────────────────────────────────────
// Validates a bulk threshold update for all ADL categories.
// Expects an array keyed by adl_category with the desired threshold_level.
// Only primary_care admin and idt admin may update thresholds (enforced in controller).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\AdlRecord;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdlThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission further validated in AdlController — this confirms authentication
        return auth()->check();
    }

    public function rules(): array
    {
        $categories = AdlRecord::CATEGORIES;
        $levels     = AdlRecord::LEVELS;

        $rules = [
            'thresholds'   => ['required', 'array'],
        ];

        // Each submitted category must be valid, and its level must be valid
        foreach ($categories as $category) {
            $rules["thresholds.{$category}"] = [
                'sometimes',
                'in:' . implode(',', $levels),
            ];
        }

        return $rules;
    }
}
