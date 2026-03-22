<?php

// ─── ApproveReconciliationRequest ─────────────────────────────────────────────
// Validates Step 5 provider approval. The approving provider must belong to
// an allowed approver department (APPROVER_DEPARTMENTS on MedReconciliation).
// Department check is enforced here so the controller stays clean.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\MedReconciliation;
use Illuminate\Foundation\Http\FormRequest;

class ApproveReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only specific departments may issue final provider approval
        $dept = $this->user()?->department;
        return in_array($dept, MedReconciliation::APPROVER_DEPARTMENTS, true);
    }

    public function rules(): array
    {
        // No body fields required — the provider's identity comes from the auth user
        return [];
    }

    public function failedAuthorization(): \Illuminate\Http\Exceptions\HttpResponseException
    {
        abort(403, 'Only primary care, pharmacy, or IT admin providers may approve medication reconciliations.');
    }
}
