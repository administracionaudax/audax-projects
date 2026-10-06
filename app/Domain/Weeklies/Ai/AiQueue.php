<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Colas de la IA externa (D-146 y D-222): un solo proceso en Horizon (supervisor-ai, config/horizon.php),
 * para no saturar la cuota de Gemini ni la memoria del servidor, que atiende primero `ai-high` y luego
 * `ai` (sin balanceo, por orden):
 *   - HIGH (`ai-high`): el informe semanal, su audio y la satisfacción del cierre. Nunca esperan detrás
 *     de las preguntas del asistente ni de los resúmenes: como mucho, a que acabe el Job en curso,
 *   - NAME (`ai`): el asistente, los resúmenes, las tareas sugeridas y la limpieza de dictados, con
 *     límites diarios por persona (AiDailyLimits).
 * Todos los Jobs son únicos (ShouldBeUnique, UNIQUE_FOR) para no encolar dos veces lo mismo. Se usan así:
 *
 *     public int $timeout = AiQueue::TIMEOUT;
 *     public function __construct(…) { $this->onQueue(AiQueue::NAME); }
 *
 * TIMEOUT es el del supervisor y queda por debajo del retry_after de la conexión redis (660 s).
 */
final class AiQueue
{
    public const string NAME = 'ai';

    public const string HIGH = 'ai-high';

    public const int TIMEOUT = 600;

    /**
     * Segundos que dura el candado de un Job único: lo que puede tardar más un minuto. Por debajo de
     * los 12 minutos con los que un trabajo se da por atascado (WeeklyJobProgress, AiSummaries y
     * TaskSuggester), así que pedirlo de nuevo tras un atasco siempre encola.
     */
    public const int UNIQUE_FOR = self::TIMEOUT + 60;
}
