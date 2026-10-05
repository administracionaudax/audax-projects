<?php

namespace App\Domain\Weeklies\Ai;

use Throwable;

/**
 * Doble de SpeechSynthesizer para los tests (y local sin clave, GOOGLE_TTS_DRIVER=fake): devuelve un
 * «MP3» falso con el texto, o el fallo que se programe. No llama a nada ni escribe en ai_usage.
 */
final class FakeSpeechSynthesizer implements SpeechSynthesizer
{
    /** @var list<Throwable> */
    private array $failures = [];

    /** @var list<SpeechRequest> */
    public array $requests = [];

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(SpeechSynthesizer::class, $fake);

        return $fake;
    }

    public function failWith(Throwable ...$failures): self
    {
        $this->failures = [...$this->failures, ...array_values($failures)];

        return $this;
    }

    public function voice(): string
    {
        return 'fake-voice';
    }

    public function synthesize(SpeechRequest $request): SynthesizedSpeech
    {
        $this->requests[] = $request;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return new SynthesizedSpeech('ID3FAKE-MP3:'.md5($request->text), 'fake-voice', mb_strlen($request->text));
    }
}
