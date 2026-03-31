<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'resolution_text' => ['required', 'string', 'min:10'],
            'resolution_date' => ['required', 'date'],
        ];
    }
}
