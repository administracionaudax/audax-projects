<?php

namespace App\Http\Requests\Chat;

/**
 * Fijar o desfijar un mensaje.
 */
class PinRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'pinned' => ['required', 'boolean'],
        ];
    }
}
