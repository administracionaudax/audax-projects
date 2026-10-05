<?php

namespace App\Domain\Weeklies;

use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;

/**
 * ÚNICA forma de escribir una weekly (D-150, F-051 y F-052). Implementación en la entrega 10.2.
 *
 * Reglas que debe cumplir (y probar):
 * 1. La semana está ACTIVA; cerrada → WeeklyRuleViolation::CYCLE_CLOSED (solo lectura).
 * 2. El autor participa esa semana (WeeklyEligibility::rosterFor()->participates()) →
 *    si no, NOT_PARTICIPANT; y no está exento (isExempt) → si no, EXEMPT (F-054).
 * 3. Un envío por persona y semana (fila única): el borrador y el envío son la misma fila.
 * 4. Los apuntes se sustituyen enteros en una transacción con WeeklyDraftData::filled(): uno por
 *    cliente (client_id nulo = «General / Interno»), sin los vacíos, con `position` en el orden
 *    recibido. Un project_id que no sea de ese cliente se descarta (null).
 * 5. saveDraft(): guarda sin enviar y pone draft_saved_at = ahora; no toca submitted_at (si ya
 *    estaba enviada, el borrador autoguardado sigue siendo la versión enviada: F-052).
 * 6. submit(): la primera vez pone submitted_at = ahora (decide si fue a tiempo, F-100); al reenviar
 *    conserva submitted_at y pone resubmitted_at = ahora. Se puede enviar fuera de plazo.
 * 7. Sin apuntes con texto no se puede enviar (error de validación en el FormRequest).
 * La autorización (WeeklySubmissionPolicy) la hace antes el controlador.
 */
interface WeeklySubmissionWriter
{
    /**
     * @throws WeeklyRuleViolation
     */
    public function saveDraft(User $author, WeeklyCycle $cycle, WeeklyDraftData $draft): WeeklySubmission;

    /**
     * @throws WeeklyRuleViolation
     */
    public function submit(User $author, WeeklyCycle $cycle, WeeklyDraftData $draft): WeeklySubmission;
}
