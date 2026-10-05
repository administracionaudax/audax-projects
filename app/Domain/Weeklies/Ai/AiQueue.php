<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Cola de la IA externa (D-146): un solo proceso en Horizon (supervisor-ai, config/horizon.php), para
 * no saturar la cuota de Gemini ni la memoria del servidor. Los Jobs de 10.3 a 10.6 la usan así:
 *
 *     public int $timeout = AiQueue::TIMEOUT;
 *     public function __construct(…) { $this->onQueue(AiQueue::NAME); }
 *
 * TIMEOUT es el del supervisor y queda por debajo del retry_after de la conexión redis (660 s).
 */
final class AiQueue
{
    public const string NAME = 'ai';

    public const int TIMEOUT = 600;
}
