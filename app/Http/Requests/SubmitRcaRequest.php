<?php

// ─── SubmitRcaRequest ─────────────────────────────────────────────────────────
// Validates the RCA submission payload.
// QA compliance and clinical dept admins may submit RCAs.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->department, [
            'qa_compliance', 'primary_care', 'it_admin',
        ], true);
    }

    public function rules(): array
    {
        return [
            'rca_text' => ['required', 'string', 'min:50', 'max:20000'],
        ];
    }

    protected function failedAuthorization(): never
    {
        abort(403, 'Only QA Compliance, Primary Care, or IT Admin may submit an RCA.');
    }
}
