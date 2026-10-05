<?php

namespace App\Domain\Weeklies\Ai;

use RuntimeException;

/**
 * Error de la IA externa (texto o locución). Los Jobs la registran en el estado de la semana
 * (report_error, audio_error) y la interfaz muestra el mensaje traducido.
 */
class LlmException extends RuntimeException
{
    public function userMessage(): string
    {
        return __('weeklies.errors.llm_unavailable');
    }
}
