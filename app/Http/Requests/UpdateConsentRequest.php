<?php

namespace App\Http\Requests;

use App\Models\ConsentRecord;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConsentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'              => ['sometimes', 'required', 'string', 'in:' . implode(',', ConsentRecord::STATUSES)],
            'acknowledged_by'     => ['nullable', 'string', 'max:200'],
            'acknowledged_at'     => ['nullable', 'date'],
            'representative_type' => ['nullable', 'string', 'in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES)],
            'notes'               => ['nullable', 'string'],
            'document_path'       => ['nullable', 'string'],
        ];
    }
}
