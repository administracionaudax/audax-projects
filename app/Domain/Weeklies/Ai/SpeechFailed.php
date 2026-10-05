<?php

namespace App\Domain\Weeklies\Ai;

use Throwable;

/**
 * La locución ha fallado (10.3): envuelve el error de SpeechSynthesizer para que el audio de la
 * semana diga que es la locución (y no Gemini) la que no está configurada o no responde.
 */
final class SpeechFailed extends LlmException
{
    public static function from(LlmException $e): self
    {
        return new self($e->getMessage(), previous: $e, notConfigured: $e instanceof LlmNotConfigured);
    }

    public function __construct(string $message = '', ?Throwable $previous = null, private readonly bool $notConfigured = false)
    {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return __($this->notConfigured ? 'weeklies.errors.tts_not_configured' : 'weeklies.errors.tts_unavailable');
    }
}
