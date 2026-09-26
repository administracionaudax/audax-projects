<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /horas/aprobaciones/{period}/devolver {comment}: el comentario es obligatorio (D-020).
 */
class ReturnWeekRequest extends TimeRequest
{
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
        $message = __('time.errors.return_comment_required');

        return is_string($message) ? ['comment.required' => $message] : [];
    }
}
