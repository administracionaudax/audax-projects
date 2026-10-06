<?php

namespace App\Domain\Weeklies\Dictation;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Enums\AiFeature;
use App\Enums\DictationContext;
use App\Models\Client;
use App\Models\Dictation;
use App\Models\Setting;
use App\Models\User;

/**
 * Limpieza opcional del dictado con IA (F-172, D-146): reescribe la transcripción de Whisper sin
 * muletillas y corrige los nombres de clientes y personas con el catálogo de Audax. Prompt portado de
 * `ws:transcribe-audio` (segunda pasada). Solo va a Gemini el TEXTO, nunca el audio.
 *
 * Detrás del ajuste `weekly_dictation_cleanup` (encendido por defecto, D-227) y solo para el dictado
 * de la weekly, como el modo `weekly-report` de WeeklySync: las notas de las tareas quedan literales.
 * Si la IA falla o devuelve algo que no parece voz útil, se queda la transcripción literal: la
 * limpieza nunca bloquea el dictado (y, si falla, se avisa con `cleanup_failed`).
 */
final class DictationCleaner
{
    public const string SETTING = 'weekly_dictation_cleanup';

    public function __construct(private readonly LlmClient $llm) {}

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING, true);
    }

    /** Si este dictado pasa por la limpieza: el ajuste encendido y un apunte de la weekly. */
    public static function appliesTo(Dictation $dictation): bool
    {
        return $dictation->context === DictationContext::WeeklyEntry && self::enabled();
    }

    /**
     * @throws LlmException
     */
    public function clean(Dictation $dictation, string $raw): string
    {
        $clients = Client::query()->where('is_active', true)->orderBy('name')->pluck('name')->implode(', ');
        $people = User::query()->where('is_active', true)->whereNull('client_id')->orderBy('name')->pluck('name')->implode(', ');

        $prompt = implode("\n", [
            'Eres un editor experto en reportes semanales internos. Vas a recibir una transcripción literal de un audio en español.',
            '',
            'CLIENTES OFICIALES: '.($clients !== '' ? $clients : 'Sin clientes de referencia'),
            'PERSONAS DEL EQUIPO: '.($people !== '' ? $people : 'Sin personas de referencia'),
            '',
            'TRANSCRIPCIÓN BRUTA:',
            $raw,
            '',
            'INSTRUCCIONES:',
            '1. Reescribe el contenido de forma clara, correcta y natural.',
            '2. Mantén intactos todos los hechos, detalles, matices, bloqueos, avances y contexto del audio.',
            '3. Elimina pausas, muletillas, repeticiones, sonidos y expresiones no verbales que dificulten la lectura.',
            '4. Corrige nombres de clientes y miembros del equipo usando SOLO el catálogo oficial proporcionado.',
            '5. Si dudas entre dos nombres, conserva el significado original sin inventar datos.',
            '6. No resumas. No omitas información relevante. No añadas información nueva.',
            '7. Si la transcripción no contiene contenido útil real, devuelve una cadena vacía.',
            '8. Devuelve solo el texto final limpio, sin introducciones ni explicaciones.',
        ]);

        $response = $this->llm->generate(new LlmRequest(
            feature: AiFeature::TranscriptCleanup,
            prompt: $prompt,
            temperature: 0.2,
            user: $dictation->user,
            subject: $dictation,
            operation: 'dictation_cleanup',
            metadata: ['context' => $dictation->context->value],
        ));

        $cleaned = DictationText::cleanModelText($response->text);

        return DictationText::isMeaningful($cleaned) ? $cleaned : $raw;
    }
}
