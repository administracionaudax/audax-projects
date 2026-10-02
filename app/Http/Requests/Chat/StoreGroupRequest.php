<?php

namespace App\Http\Requests\Chat;

/**
 * Grupo nuevo: nombre y personas (además de quien lo crea). ConversationDirectory::group se queda
 * solo con las internas activas y exige al menos otra.
 */
class StoreGroupRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
