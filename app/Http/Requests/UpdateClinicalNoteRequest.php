<?php

// ─── UpdateClinicalNoteRequest ────────────────────────────────────────────────
// Validates editing a draft clinical note.
// Authorization: only the original author can edit, and only while status = 'draft'.
// The canEdit check is re-enforced in ClinicalNoteController::update() for clarity.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ClinicalNote;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller also enforces canEdit() — this is a belt-and-suspenders check
        $note = $this->route('note');
        return $note instanceof ClinicalNote && $note->canEdit(auth()->user());
    }

    public function rules(): array
    {
        return [
            'note_type'          => ['sometimes', 'in:' . implode(',', ClinicalNote::NOTE_TYPES)],
            'visit_type'         => ['sometimes', 'in:in_center,home_visit,telehealth,phone'],
            'visit_date'         => ['sometimes', 'date', 'before_or_equal:today'],
            'visit_time'         => ['nullable', 'date_format:H:i'],

            'subjective'         => ['nullable', 'string', 'max:10000'],
            'objective'          => ['nullable', 'string', 'max:10000'],
            'assessment'         => ['nullable', 'string', 'max:10000'],
            'plan'               => ['nullable', 'string', 'max:10000'],
            'content'            => ['nullable', 'array'],

            'is_late_entry'      => ['boolean'],
            'late_entry_reason'  => ['required_if:is_late_entry,true', 'nullable', 'string', 'max:500'],
        ];
    }
}
