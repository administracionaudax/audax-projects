<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Audio\WeeklyAudioGenerator;
use App\Domain\Weeklies\WeeklyJobProgress;
use App\Enums\WeeklyJobState;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Genera (o regenera) el audio del informe por secciones (F-084, D-146 y D-190), en la cola `ai`.
 * También con la semana cerrada (en WeeklySync se podía regenerar el audio, no el texto). Un solo
 * intento: si falla, audio_state `failed` con el mensaje en audio_error, y el audio anterior sigue.
 */
final class GenerateWeeklyAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    public function __construct(public readonly int $cycleId, public readonly ?int $userId = null)
    {
        $this->onQueue(AiQueue::NAME);
    }

    public function handle(WeeklyAudioGenerator $generator): void
    {
        $cycle = WeeklyCycle::query()->find($this->cycleId);

        if ($cycle === null) {
            return;
        }

        if ($cycle->report === null) {
            $this->markFailed($cycle, null);

            return;
        }

        $user = $this->userId === null ? null : User::query()->find($this->userId);
        $cycle->forceFill(['audio_state' => WeeklyJobState::Running, 'audio_error' => null])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::AUDIO, WeeklyJobState::Running, 'scripts');

        try {
            $generator->generate($cycle, $user, function (int $done, int $total, string $step) use ($cycle): void {
                WeeklyJobProgress::update($cycle, WeeklyJobProgress::AUDIO, WeeklyJobState::Running, $step, $done, $total);
            });
        } catch (Throwable $e) {
            $this->markFailed($cycle, $e);

            return;
        }

        WeeklyJobProgress::update($cycle, WeeklyJobProgress::AUDIO, WeeklyJobState::Done);
    }

    public function failed(?Throwable $exception): void
    {
        $cycle = WeeklyCycle::query()->find($this->cycleId);

        if ($cycle !== null && $cycle->audio_state?->isBusy()) {
            $this->markFailed($cycle, $exception);
        }
    }

    private function markFailed(WeeklyCycle $cycle, ?Throwable $e): void
    {
        if ($e !== null && ! $e instanceof LlmException) {
            report($e);
        }

        $message = match (true) {
            $e instanceof LlmException => $e->userMessage(),
            $cycle->report === null => __('weeklies.errors.audio_needs_report'),
            default => __('weeklies.errors.audio_failed'),
        };
        $cycle->forceFill(['audio_state' => WeeklyJobState::Failed, 'audio_error' => $message])->save();
        WeeklyJobProgress::update($cycle, WeeklyJobProgress::AUDIO, WeeklyJobState::Failed, error: $message);
    }
}
