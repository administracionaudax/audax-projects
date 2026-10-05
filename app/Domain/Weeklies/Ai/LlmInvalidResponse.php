<?php

namespace App\Domain\Weeklies\Ai;

/** La respuesta llega sin texto, bloqueada o con un JSON que no se puede leer. */
final class LlmInvalidResponse extends LlmException
{
    public function userMessage(): string
    {
        return __('weeklies.errors.llm_invalid_response');
    }
}
