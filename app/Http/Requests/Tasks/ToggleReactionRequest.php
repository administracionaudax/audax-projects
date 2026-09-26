<?php

namespace App\Http\Requests\Tasks;

use App\Models\CommentReaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Activar o quitar una reacción de la lista cerrada CommentReaction::EMOJIS.
 */
class ToggleReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(CommentReaction::EMOJIS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emoji.in' => __('tasks.errors.reaction_invalid'),
        ];
    }
}
