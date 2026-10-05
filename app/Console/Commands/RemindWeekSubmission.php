<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Time\Capacity;
use App\Domain\Time\Week;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Reminders\WeeklyNotifier;
use App\Domain\Weeklies\Reminders\WeeklyReminderRecipients;
use App\Enums\AppModule;
use App\Enums\TimesheetStatus;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Notifications\Time\WeekSubmissionReminder;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recordatorio de los viernes (SPEC §13, D-073 y D-200): los viernes a las 13:00 de Madrid
 * (routes/console.php), UN SOLO aviso por persona con lo que le falta (D-150: no se avisa dos veces):
 * - la semana de horas (ajuste week_reminder_enabled): a cada persona interna y activa que tiene
 *   capacidad esa semana (App\Domain\Time\Capacity: jornada menos festivos y ausencias aprobadas;
 *   una semana de vacaciones o de festivos no avisa) y no la ha enviado (sin semana guardada, abierta
 *   o devuelta; enviada, aprobada o bloqueada, no),
 * - la weekly (ajuste weekly_friday_reminder y el módulo encendido): a quien debe enviar la semana
 *   activa y aún no lo ha hecho (WeeklyReminderRecipients: sin exentos ni colaboradores externos).
 *   Esa parte queda en el registro de avisos de la Weekly (plantilla «friday»).
 * Con las dos partes desactivadas no hace nada (el programador tampoco lo lanza).
 *
 * Una sola vez por persona y semana, aunque el comando se ejecute varias veces: cada aviso se
 * reclama con Cache::add (atómico) hasta pasada la semana y, por si la caché se vaciara, no se
 * repite a quien ya tiene el recordatorio de esta semana en la campana; la parte de la weekly,
 * además, por su fila del registro (friday:{semana}:{fecha}).
 *
 * Consultas acotadas, sea cual sea el número de personas: personas, semanas, capacidad (horarios,
 * festivos y ausencias), horas imputadas y avisos ya enviados, una consulta de cada; con la weekly,
 * la semana activa, su foto, los envíos y el registro.
 */
#[Signature('time:remind-week')]
#[Description('Recordatorio de los viernes: la semana de horas y la weekly que aún no se han enviado')]
class RemindWeekSubmission extends Command
{
    /**
     * Clave con la que una ejecución reclama el recordatorio de una persona para una semana (lunes, Y-m-d).
     */
    public static function claimKey(int $userId, string $weekStart): string
    {
        return "time-week-reminder:{$userId}:{$weekStart}";
    }

    public function handle(
        Capacity $capacity,
        NotificationPreferences $preferences,
        WeeklyReminderRecipients $weeklyRecipients,
        WeeklyNotifier $notifier,
    ): int {
        $hoursEnabled = (bool) Setting::get('week_reminder_enabled', true);
        $weeklyEnabled = (bool) Setting::get('weekly_friday_reminder', true) && AppModules::enabled(AppModule::Weeklies);

        if (! $hoursEnabled && ! $weeklyEnabled) {
            $this->info('El recordatorio de enviar la semana está desactivado en los ajustes.');

            return self::SUCCESS;
        }

        $week = Week::current();
        $weekStart = $week->startString();
        // El lunes a las 00:00 de Madrid, en UTC: lo enviado desde entonces es de esta semana.
        $weekStartsAt = CarbonImmutable::parse($weekStart, LocalTime::timezone())->utc();
        // Hasta pasado el domingo en Madrid (con margen).
        $claimUntil = CarbonImmutable::parse($week->endString(), LocalTime::timezone())->addDays(2);

        $people = new Collection;
        $statuses = collect();

        if ($hoursEnabled) {
            $statuses = TimesheetPeriod::query()
                ->where('week_start', $weekStart)
                ->pluck('status', 'user_id');

            $closed = [TimesheetStatus::Submitted, TimesheetStatus::Approved, TimesheetStatus::Locked];

            $people = User::query()->active()->internal()->orderBy('id')->get()
                ->reject(fn (User $user): bool => in_array($statuses->get($user->id), $closed, true))
                ->values();
        }

        // La weekly pendiente de la semana activa (D-200).
        $cycle = $weeklyEnabled ? WeeklyCycle::query()->active()->first() : null;
        $weeklyPending = $cycle !== null ? $weeklyRecipients->pending($cycle)->keyBy('id') : new Collection;
        $weeklyPart = $cycle !== null ? ['cycle_id' => $cycle->id, 'label' => $cycle->label, 'deadline' => $cycle->deadline_date->toDateString()] : null;

        if ($people->isEmpty() && $weeklyPending->isEmpty()) {
            $this->info('Nadie tiene la semana pendiente de enviar.');

            return self::SUCCESS;
        }

        $capacities = [];
        $logged = collect();

        if ($people->isNotEmpty()) {
            $capacities = $capacity->forRanges(array_values($people->map(fn (User $user): array => [
                'user_id' => $user->id,
                'from' => $week->start,
                'to' => $week->end(),
            ])->all()));

            $logged = TimeEntry::query()
                ->whereIn('user_id', $people->modelKeys())
                ->between($weekStart, $week->endString())
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(minutes) AS logged_minutes')
                ->pluck('logged_minutes', 'user_id');
        }

        /** @var array<int, int> $hoursDue persona → capacidad de la semana */
        $hoursDue = [];

        foreach ($people as $index => $user) {
            $capacityMinutes = array_sum($capacities[$index]);

            if ($capacityMinutes > 0) {
                $hoursDue[$user->id] = $capacityMinutes;
            }
        }

        /** @var array<int, User> $candidates */
        $candidates = [];

        foreach ($people as $user) {
            if (isset($hoursDue[$user->id])) {
                $candidates[$user->id] = $user;
            }
        }

        foreach ($weeklyPending as $user) {
            $candidates[$user->id] ??= $user;
        }

        ksort($candidates);
        $ids = array_keys($candidates);

        $reminded = $ids === [] ? [] : DatabaseNotification::query()
            ->where('type', WeekSubmissionReminder::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->where('created_at', '>=', $weekStartsAt)
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // Primera pasada: a quién y por qué canales (sin canal activado para este aviso, nada).
        $plan = [];

        foreach ($candidates as $userId => $user) {
            if (in_array($userId, $reminded, true)) {
                continue;
            }

            $channels = $preferences->channelsFor($user, 'time.week_reminder');

            if ($channels === null || $channels === []) {
                continue;
            }

            $plan[$userId] = $channels;
        }

        // La parte de la weekly se reclama en el registro de una vez (su deduplicación).
        $weeklyLogs = [];

        if ($weeklyPart !== null && $weeklyPending->isNotEmpty()) {
            $rows = [];

            foreach (array_keys($plan) as $userId) {
                if (! $weeklyPending->has($userId)) {
                    continue;
                }

                foreach ($plan[$userId] as $laravel) {
                    $logical = WeeklyNotifier::logicalChannel($laravel);

                    if ($logical !== null) {
                        $rows[] = ['user' => $candidates[$userId], 'channel' => $logical, 'status' => WeeklyReminderStatus::Queued, 'error' => null];
                    }
                }
            }

            $claimed = $notifier->claim($cycle, WeeklyReminderTemplate::Friday, "friday:{$weeklyPart['cycle_id']}:".CarbonImmutable::now(LocalTime::timezone())->toDateString(), $rows);

            foreach ($claimed as $userId => $byChannel) {
                foreach ($byChannel as $channel => $logId) {
                    $laravel = match ($channel) {
                        WeeklyReminderChannel::App->value => 'database',
                        WeeklyReminderChannel::Email->value => 'mail',
                        default => (string) config('notifications.channels.push'),
                    };
                    // Con el resumen diario, el email va a la campana (canal database).
                    $laravel = in_array($laravel, $plan[$userId], true) ? $laravel : 'database';
                    $weeklyLogs[$userId][$laravel][] = $logId;
                }
            }
        }

        $sent = 0;

        foreach ($plan as $userId => $channels) {
            $user = $candidates[$userId];
            $hours = isset($hoursDue[$userId]);
            $weekly = isset($weeklyLogs[$userId]);

            if (! $hours && ! $weekly) {
                continue;
            }

            $claim = self::claimKey($userId, $weekStart);

            if (! Cache::add($claim, true, $claimUntil)) {
                continue;
            }

            $notification = new WeekSubmissionReminder(
                weekStart: $weekStart,
                loggedMinutes: (int) $logged->get($userId, 0),
                capacityMinutes: $hoursDue[$userId] ?? 0,
                returned: $statuses->get($userId) === TimesheetStatus::Returned,
                hours: $hours,
                weekly: $weekly ? $weeklyPart : null,
            );
            $notification->reminderLogIds = $weeklyLogs[$userId] ?? [];

            try {
                $user->notify($notification);
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
