<?php

namespace App\Http\Requests\Chat;

/**
 * Añadir personas a un grupo (D-119). ConversationDirectory::addToGroup se queda con las internas
 * activas que aún no están y comprueba quién puede.
 */
class AddGroupMembersRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
