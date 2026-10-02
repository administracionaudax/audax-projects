<?php

namespace App\Http\Requests\Chat;

/**
 * Renombrar un grupo (D-119). ConversationDirectory::renameGroup comprueba quién puede.
 */
class UpdateGroupRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
