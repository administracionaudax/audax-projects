<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Time\Capacity;
use App\Domain\Time\Week;
use App\Enums\TimesheetStatus;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Notifications\Time\WeekSubmissionReminder;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recordatorio de enviar la semana (SPEC §13, D-073): los viernes a las 13:00 de Madrid
 * (routes/console.php), a cada persona interna y activa que
 * - tiene capacidad esa semana (App\Domain\Time\Capacity: jornada menos festivos y ausencias
 *   aprobadas; una semana de vacaciones o de festivos no avisa),
 * - y no la ha enviado: sin semana guardada, abierta o devuelta (enviada, aprobada o bloqueada, no).
 * No hace nada con el ajuste week_reminder_enabled desactivado (el programador tampoco lo lanza).
 *
 * Una sola vez por persona y semana, aunque el comando se ejecute varias veces: cada aviso se
 * reclama con Cache::add (atómico) hasta pasada la semana y, por si la caché se vaciara, no se
 * repite a quien ya tiene el recordatorio de esta semana en la campana.
 *
 * Consultas acotadas, sea cual sea el número de personas: personas, semanas, capacidad (horarios,
 * festivos y ausencias), horas imputadas y avisos ya enviados, una consulta de cada.
 */
#[Signature('time:remind-week')]
#[Description('Recuerda enviar la semana a quien tiene capacidad y aún no la ha enviado (viernes)')]
class RemindWeekSubmission extends Command
{
    /**
     * Clave con la que una ejecución reclama el recordatorio de una persona para una semana (lunes, Y-m-d).
     */
    public static function claimKey(int $userId, string $weekStart): string
    {
        return "time-week-reminder:{$userId}:{$weekStart}";
    }

    public function handle(Capacity $capacity, NotificationPreferences $preferences): int
    {
        if (! (bool) Setting::get('week_reminder_enabled', true)) {
            $this->info('El recordatorio de enviar la semana está desactivado en los ajustes.');

            return self::SUCCESS;
        }

        $week = Week::current();
        $weekStart = $week->startString();
        // El lunes a las 00:00 de Madrid, en UTC: lo enviado desde entonces es de esta semana.
        $weekStartsAt = CarbonImmutable::parse($weekStart, LocalTime::timezone())->utc();
        // Hasta pasado el domingo en Madrid (con margen).
        $claimUntil = CarbonImmutable::parse($week->endString(), LocalTime::timezone())->addDays(2);

        $statuses = TimesheetPeriod::query()
            ->where('week_start', $weekStart)
            ->pluck('status', 'user_id');

        $closed = [TimesheetStatus::Submitted, TimesheetStatus::Approved, TimesheetStatus::Locked];

        $people = User::query()->active()->internal()->orderBy('id')->get()
            ->reject(fn (User $user): bool => in_array($statuses->get($user->id), $closed, true))
            ->values();

        if ($people->isEmpty()) {
            $this->info('Nadie tiene la semana pendiente de enviar.');

            return self::SUCCESS;
        }

        $ids = $people->modelKeys();

        $capacities = $capacity->forRanges(array_values($people->map(fn (User $user): array => [
            'user_id' => $user->id,
            'from' => $week->start,
            'to' => $week->end(),
        ])->all()));

        $logged = TimeEntry::query()
            ->whereIn('user_id', $ids)
            ->between($weekStart, $week->endString())
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(minutes) AS logged_minutes')
            ->pluck('logged_minutes', 'user_id');

        $reminded = DatabaseNotification::query()
            ->where('type', WeekSubmissionReminder::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->where('created_at', '>=', $weekStartsAt)
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $sent = 0;

        foreach ($people as $index => $user) {
            $capacityMinutes = array_sum($capacities[$index]);

            if ($capacityMinutes <= 0 || in_array($user->id, $reminded, true)) {
                continue;
            }

            // Sin ningún canal activado para este aviso, no hay nada que enviar.
            if ($preferences->channelsFor($user, 'time.week_reminder') === []) {
                continue;
            }

            $claim = self::claimKey($user->id, $weekStart);

            if (! Cache::add($claim, true, $claimUntil)) {
                continue;
            }

            try {
                $user->notify(new WeekSubmissionReminder(
                    weekStart: $weekStart,
                    loggedMinutes: (int) $logged->get($user->id, 0),
                    capacityMinutes: $capacityMinutes,
                    returned: $statuses->get($user->id) === TimesheetStatus::Returned,
                ));
            } catch (Throwable $exception) {
                Cache::forget($claim);

                throw $exception;
            }

            $sent++;
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
