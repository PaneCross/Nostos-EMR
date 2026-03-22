<?php

// ─── StoreReferralRequest ─────────────────────────────────────────────────────
// Validates a new referral creation. Status defaults to 'new' (set in controller).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Referral;
use Illuminate\Foundation\Http\FormRequest;

class StoreReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'site_id'          => ['required', 'integer', 'exists:shared_sites,id'],
            'referred_by_name' => ['required', 'string', 'max:150'],
            'referred_by_org'  => ['nullable', 'string', 'max:150'],
            'referral_date'    => ['required', 'date'],
            'referral_source'  => ['required', 'string', 'in:' . implode(',', Referral::SOURCES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'            => ['nullable', 'string', 'max:5000'],
        ];
    }
}
