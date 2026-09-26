<?php

namespace App\Console\Commands;

use App\Models\ActiveTimer;
use App\Models\Setting;
use App\Notifications\Time\TimerRunningLong;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Avisa en la app a quien tiene un temporizador en marcha desde hace más de timer_warning_hours
 * (SPEC §7). Cada temporizador avisa una sola vez (warned_at). Programado cada hora en
 * routes/console.php; la cabecera muestra además el aviso en vivo.
 */
#[Signature('timers:warn')]
#[Description('Avisa de los temporizadores que llevan demasiadas horas en marcha')]
class WarnLongTimers extends Command
{
    public function handle(): int
    {
        $hours = max((int) Setting::get('timer_warning_hours', 10), 1);
        $limit = now()->subHours($hours);
        $warned = 0;

        ActiveTimer::query()
            ->whereNull('warned_at')
            ->where('started_at', '<=', $limit)
            ->with([
                'user',
                'task' => fn ($query) => $query->withTrashed()->select(['id', 'title']),
            ])
            ->orderBy('user_id')
            ->chunkById(100, function ($timers) use ($hours, &$warned): void {
                /** @var ActiveTimer $timer */
                foreach ($timers as $timer) {
                    // Marca antes de avisar: si dos ejecuciones se solapan, solo una lo consigue.
                    $claimed = ActiveTimer::query()
                        ->whereKey($timer->user_id)
                        ->whereNull('warned_at')
                        ->update(['warned_at' => now()]);

                    if ($claimed === 0 || ! $timer->user->is_active) {
                        continue;
                    }

                    $timer->user->notify(new TimerRunningLong(
                        taskTitle: $timer->task->title,
                        hours: $hours,
                        startedOn: LocalTime::dateOf($timer->started_at),
                    ));
                    $warned++;
                }
            }, 'user_id');

        $this->info("Avisos enviados: {$warned}.");

        return self::SUCCESS;
    }
}
