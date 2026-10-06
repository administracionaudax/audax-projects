<?php

namespace App\Http\Requests\Chat;

/**
 * Canal de equipo nuevo (D-272): nombre y emoji. ConversationDirectory::createTeam comprueba que
 * quien lo crea es admin y que el emoji es uno del selector.
 */
class StoreChannelRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:16'],
        ];
    }
}
