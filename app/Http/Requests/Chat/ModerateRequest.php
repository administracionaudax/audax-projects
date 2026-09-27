<?php

namespace App\Http\Requests\Chat;

/**
 * Ocultar o volver a mostrar un mensaje (moderación del admin, D-071).
 */
class ModerateRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'hidden' => ['required', 'boolean'],
        ];
    }
}
