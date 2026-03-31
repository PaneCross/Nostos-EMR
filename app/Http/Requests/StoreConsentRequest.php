<?php

namespace App\Http\Requests;

use App\Models\ConsentRecord;
use Illuminate\Foundation\Http\FormRequest;

class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'consent_type'        => ['required', 'string', 'in:' . implode(',', ConsentRecord::CONSENT_TYPES)],
            'document_title'      => ['required', 'string', 'max:300'],
            'document_version'    => ['nullable', 'string', 'max:50'],
            'status'              => ['required', 'string', 'in:' . implode(',', ConsentRecord::STATUSES)],
            'acknowledged_by'     => ['nullable', 'string', 'max:200'],
            'acknowledged_at'     => ['nullable', 'date'],
            'representative_type' => ['nullable', 'string', 'in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES)],
            'expiration_date'     => ['nullable', 'date'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
