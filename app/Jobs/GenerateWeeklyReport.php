<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\WeeklyJobProgress;
use App\Domain\Weeklies\WeeklyReportGenerator;
use App\Enums\WeeklyJobState;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Genera (o regenera) el informe de una semana con la IA (F-072, D-146 y D-190), en la cola `ai`.
 * Un solo intento: si falla, la semana queda con report_state `failed` y el mensaje en report_error,
 * y quien gestiona lo vuelve a pedir. Al terminar guarda el informe, su texto, cuántos envíos había
 * (para «Hay nuevos reportes») y quién y cuándo; una edición anterior a mano se pierde.
 */
final class GenerateWeeklyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    public function __construct(public readonly int $cycleId, public readonly ?int $userId = null)
    {
        $this->onQueue(AiQueue::NAME);
    }

    public function handle(WeeklyReportGenerator $generator): void
    {
        $cycle = WeeklyCycle::query()->find($this->cycleId);

        if ($cycle === null || $cycle->isClosed()) {
            return;
        }

        $user = $this->userId === null ? null : User::query()->find($this->userId);
        $cycle->forceFill(['report_state' => WeeklyJobState::Running, 'report_error' => null])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::REPORT, WeeklyJobState::Running, 'clients');

        try {
            $result = $generator->generate($cycle, $user, function (int $done, int $total, string $step) use ($cycle): void {
                WeeklyJobProgress::update($cycle, WeeklyJobProgress::REPORT, WeeklyJobState::Running, $step, $done, $total);
            });
        } catch (Throwable $e) {
            $this->markFailed($cycle, $e);

            return;
        }

        $cycle->forceFill([
            'report' => $result->report->toArray(),
            'report_text' => $result->text,
            'submission_count_at_generation' => $result->submissionCount,
            'report_state' => WeeklyJobState::Done,
            'report_error' => null,
            'report_generated_at' => now(),
            'report_generated_by' => $user?->id,
            'report_edited_at' => null,
            'report_edited_by' => null,
        ])->save();

        WeeklyJobProgress::update($cycle, WeeklyJobProgress::REPORT, WeeklyJobState::Done);
    }

    /** El worker lo ha cortado (tiempo agotado o memoria): la semana no se queda «generando». */
    public function failed(?Throwable $exception): void
    {
        $cycle = WeeklyCycle::query()->find($this->cycleId);

        if ($cycle !== null && $cycle->report_state?->isBusy()) {
            $this->markFailed($cycle, $exception);
        }
    }

    private function markFailed(WeeklyCycle $cycle, ?Throwable $e): void
    {
        if ($e !== null && ! $e instanceof LlmException) {
            report($e);
        }

        $message = $e instanceof LlmException ? $e->userMessage() : __('weeklies.errors.report_failed');
        $cycle->forceFill(['report_state' => WeeklyJobState::Failed, 'report_error' => $message])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::REPORT, WeeklyJobState::Failed, error: $message);
    }
}
