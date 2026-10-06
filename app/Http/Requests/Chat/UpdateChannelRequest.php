<?php

namespace App\Http\Requests\Chat;

/**
 * Cambiar un canal de equipo (D-272): nombre, emoji y archivado. ConversationDirectory::updateTeam
 * comprueba quién puede.
 */
class UpdateChannelRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:16'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
