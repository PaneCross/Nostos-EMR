<?php

namespace App\Http\Requests;

use App\Models\Grievance;
use Illuminate\Foundation\Http\FormRequest;

class NotifyParticipantGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'notification_method' => [
                'required', 'string',
                'in:' . implode(',', Grievance::NOTIFICATION_METHODS),
            ],
        ];
    }
}
