<?php

namespace App\Http\Requests\Chat;

use App\Domain\Chat\MessageWriter;

class UpdateMessageRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.MessageWriter::MAX_BODY],
        ];
    }
}
