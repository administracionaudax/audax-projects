<?php

namespace App\Http\Requests\Chat;

use App\Domain\Chat\MessageWriter;

/**
 * Mensaje de texto nuevo (markdown ligero, D-069), opcionalmente en respuesta a otro (hilo).
 * Los audios y adjuntos llegan por su propia ruta (área C3) y pasan igualmente por MessageWriter.
 */
class StoreMessageRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.MessageWriter::MAX_BODY],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
