<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Modelo de lenguaje para el TEXTO de la Weekly (D-146): informe, satisfacción, guion del audio,
 * tareas sugeridas, resúmenes, asistente y limpieza del dictado. El audio nunca pasa por aquí.
 *
 * - Se llama SIEMPRE desde un Job de la cola `ai` (AiQueue), nunca dentro de una petición web.
 * - Implementaciones: GeminiClient (producción) y FakeLlm (tests y local sin clave).
 * - Cada llamada queda en ai_usage (AiUsageRecorder), con éxito o error.
 *
 * @throws LlmNotConfigured sin clave de API
 * @throws LlmUnavailable si el servicio falla tras los reintentos (429, 5xx o red)
 * @throws LlmInvalidResponse si la respuesta no trae texto o, con json, no es JSON válido
 */
interface LlmClient
{
    public function generate(LlmRequest $request): LlmResponse;

    /** Modelo que se usará (config services.gemini.model, cambiable sin desplegar, F-174). */
    public function model(): string;
}
