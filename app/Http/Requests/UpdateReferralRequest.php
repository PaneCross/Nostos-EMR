<?php

// ─── UpdateReferralRequest ────────────────────────────────────────────────────
// Validates referral updates (not status transitions — those use TransitionRequest).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Referral;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'referred_by_name'    => ['sometimes', 'string', 'max:150'],
            'referred_by_org'     => ['sometimes', 'nullable', 'string', 'max:150'],
            'referral_date'       => ['sometimes', 'date'],
            'referral_source'     => ['sometimes', 'string', 'in:' . implode(',', Referral::SOURCES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:shared_users,id'],
            'participant_id'      => ['sometimes', 'nullable', 'integer', 'exists:emr_participants,id'],
            'notes'               => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
