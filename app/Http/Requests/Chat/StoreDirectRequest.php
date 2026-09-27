<?php

namespace App\Http\Requests\Chat;

class StoreDirectRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
