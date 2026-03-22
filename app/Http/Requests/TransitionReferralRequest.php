<?php

// ─── TransitionReferralRequest ────────────────────────────────────────────────
// Validates a status transition request for a referral.
// Reason fields are required conditionally: decline_reason when transitioning to
// 'declined', withdrawn_reason when transitioning to 'withdrawn'.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Referral;
use Illuminate\Foundation\Http\FormRequest;

class TransitionReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'new_status'       => ['required', 'string', 'in:' . implode(',', Referral::STATUSES)],
            'notes'            => ['nullable', 'string', 'max:5000'],
            'decline_reason'   => ['required_if:new_status,declined', 'nullable', 'string', 'max:300'],
            'withdrawn_reason' => ['required_if:new_status,withdrawn', 'nullable', 'string', 'max:300'],
        ];
    }
}
