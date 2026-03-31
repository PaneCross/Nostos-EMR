<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'investigation_notes'  => ['nullable', 'string'],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'cms_reportable'       => ['boolean'],
            'cms_reported_at'      => ['nullable', 'date'],
        ];
    }
}
