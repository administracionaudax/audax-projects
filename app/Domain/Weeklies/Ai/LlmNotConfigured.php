<?php

namespace App\Domain\Weeklies\Ai;

/** Falta la clave (GEMINI_API_KEY o GOOGLE_TTS_API_KEY): no se reintenta. */
final class LlmNotConfigured extends LlmException
{
    public function userMessage(): string
    {
        return __('weeklies.errors.llm_not_configured');
    }
}
