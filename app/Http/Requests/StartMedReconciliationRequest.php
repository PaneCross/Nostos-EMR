<?php

// ─── StartMedReconciliationRequest ────────────────────────────────────────────
// Validates the Step 1 payload for opening a new medication reconciliation
// (or returning the existing in-progress one). Ensures prior_source and type
// are restricted to the allowed enumerations defined on MedReconciliation.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\MedReconciliation;
use Illuminate\Foundation\Http\FormRequest;

class StartMedReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prior_source' => ['required', 'string', 'in:' . implode(',', MedReconciliation::SOURCES)],
            'type'         => ['required', 'string', 'in:' . implode(',', MedReconciliation::TYPES)],
        ];
    }
}
