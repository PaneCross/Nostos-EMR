<?php

// ─── StoreDocumentRequest ─────────────────────────────────────────────────────
// Validates a participant document upload.
//
// Authorization: any authenticated user belonging to the participant's tenant
// may upload. Department-level access is enforced upstream by
// CheckDepartmentAccess middleware (the documents module is readable by all).
//
// Constraints (HIPAA + storage hygiene):
//   - Max file size: 20 MB (20480 KB)
//   - Accepted MIME types: pdf, jpeg, png, docx
//   - document_category must be one of Document::VALID_CATEGORIES
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant isolation is enforced in DocumentController::store() via
        // participantForTenant() — the form request itself allows any authed user.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480',                            // 20 MB in kilobytes
                'mimes:pdf,jpeg,jpg,png,docx',
            ],
            'document_category' => [
                'required',
                'string',
                'in:' . implode(',', Document::VALID_CATEGORIES),
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max'           => 'Documents may not exceed 20 MB.',
            'file.mimes'         => 'Accepted file types: PDF, JPEG, PNG, DOCX.',
            'document_category.in' => 'Invalid document category.',
        ];
    }
}
