<?php

namespace App\Http\Requests;

use App\Models\Grievance;
use Illuminate\Foundation\Http\FormRequest;

class StoreGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'filed_by_name'        => ['required', 'string', 'max:200'],
            'filed_by_type'        => ['required', 'string', 'in:' . implode(',', Grievance::FILED_BY_TYPES)],
            'filed_at'             => ['nullable', 'date'],
            'category'             => ['required', 'string', 'in:' . implode(',', Grievance::CATEGORIES)],
            'description'          => ['required', 'string', 'min:10'],
            'priority'             => ['nullable', 'string', 'in:standard,urgent'],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'cms_reportable'       => ['boolean'],
        ];
    }
}
