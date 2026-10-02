<?php

namespace App\Http\Requests\Chat;

/**
 * Silenciar o reactivar una conversación.
 */
class MuteRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'muted' => ['required', 'boolean'],
        ];
    }
}
