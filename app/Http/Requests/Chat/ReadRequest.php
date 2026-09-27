<?php

namespace App\Http\Requests\Chat;

class ReadRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'message_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
