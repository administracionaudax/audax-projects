<?php

namespace App\Domain\Weeklies;

use App\Domain\Weeklies\Ai\LlmException;
use App\Models\User;
use App\Models\WeeklyCycle;
use Closure;

/**
 * Genera el informe de una semana con la IA (D-146, F-072 a F-076). Implementación (10.3):
 * LlmWeeklyReportGenerator, con los prompts de `ws:generate-weekly-report/pipeline.js`
 * (Report\ReportPipeline). Se llama SOLO desde un Job de la cola `ai` (GenerateWeeklyReport), nunca
 * en la petición web. $progress (añadido en 10.3) recibe (hechos, total, paso) para el progreso por
 * Reverb; paso es `clients` o `summary`.
 *
 * Lo que debe hacer:
 * - agrupar los apuntes ENVIADOS por cliente activo, en lotes de 9.000 caracteres, fusionarlos por
 *   cliente y escribir el resumen global, siempre en español (traducción forzada, F-076),
 * - añadir a cada cliente el contexto real de Audax: horas de la semana por proyecto y estado de las
 *   bolsas (HourBankLedger), y guardar la foto de sus proyectos (WeeklyProjectSnapshot, F-074),
 * - un cliente sin apuntes recibe «Sin novedades» (has_reports = false) y su estado por consumo:
 *   más del 100 % Blocked, desde el 85 % Risk; en un fee, un exceso sobre lo esperado lo sube a Risk,
 *   o a Blocked si son 4 h o más (F-075),
 * - todas las llamadas por LlmClient (FakeLlm en los tests).
 * Quien lo llame guarda en la semana report, report_text, submission_count_at_generation,
 * report_generated_at/by y report_state, y deja satisfaction_score de cada cliente como estaba.
 *
 * @throws LlmException
 */
interface WeeklyReportGenerator
{
    /**
     * @param  (Closure(int, int, string): void)|null  $progress
     */
    public function generate(WeeklyCycle $cycle, ?User $requestedBy = null, ?Closure $progress = null): GeneratedWeeklyReport;
}
