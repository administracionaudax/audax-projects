<?php

namespace App\Http\Requests\Absences;

use App\Models\Absence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Rechazar una solicitud: el comentario es obligatorio (D-049). Solo quien puede revisarla
 * (AbsencePolicy::review).
 */
class RejectAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $absence = $this->route('absence');

        return $absence instanceof Absence && Gate::allows('review', $absence);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => (string) __('absences.errors.reject_comment_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['comment' => (string) __('absences.attributes.comment')];
    }
}
