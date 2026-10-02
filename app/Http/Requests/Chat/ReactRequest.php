<?php

namespace App\Http\Requests\Chat;

/**
 * Poner o quitar una reacción. MessageWriter comprueba además que sea un emoji (sin letras,
 * números ni etiquetas).
 */
class ReactRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'max:32'],
        ];
    }
}
