<?php

namespace App\Domain\People;

use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\People\ClockReminder;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Avisos del registro de jornada (PLAN-FASE-11 §10; D-339; W-110 a W-112). Solo recuerdan: nunca
 * fichan por la persona ni cierran una jornada.
 *
 * - **Entrada**: en un día con jornada (sin festivo ni ausencia de día completo), si aún no ha
 *   fichado pasados 15 minutos del final de su margen de entrada (o de las 10:00 si su jornada no
 *   tiene margen), y durante las 4 horas siguientes.
 * - **Salida**: si sigue trabajando 30 minutos después de su salida prevista (la primera entrada
 *   de hoy + la jornada teórica + la pausa prevista o la que ya ha hecho, si es más larga).
 * - **Jornada sin cerrar**: desde las 8:00, si ayer se quedó sin salida o sin ningún fichaje.
 * - Una sola vez por persona, día y tipo (`clock_reminders`), por los canales que tenga activados.
 *   Con el módulo apagado de verdad (también en modo de prueba, D-239) no sale nada.
 */
final class ClockReminders
{
    public const string DEFAULT_START_TO = '10:00';

    public const int GRACE_IN_MINUTES = 15;

    public const int WINDOW_IN_MINUTES = 240;

    public const int GRACE_OUT_MINUTES = 30;

    public const string UNCLOSED_FROM = '08:00';

    public function __construct(
        private readonly WorkdayCalculator $calculator,
        private readonly NotificationPreferences $preferences,
    ) {}

    public function sendDue(?CarbonImmutable $now = null): int
    {
        if (! AppModules::enabled(AppModule::People)) {
            return 0;
        }

        $now ??= CarbonImmutable::now();
        $zone = LocalTime::timezone();
        $local = $now->setTimezone($zone);
        $today = $local->toDateString();
        $yesterday = $local->subDay()->toDateString();

        $people = User::query()
            ->active()
            ->role([Role::Admin->value, Role::DepartmentManager->value, Role::Employee->value])
            ->withoutCollaborators()
            ->whereDoesntHave('employmentProfile', fn (Builder $profile) => $profile->where('subject_to_register', false))
            ->with('employmentProfile')
            ->orderBy('id')
            ->get();

        if ($people->isEmpty()) {
            return 0;
        }

        $days = $this->calculator->forUsers($people->all(), $yesterday, $today, null, $now);
        $sent = 0;

        foreach ($people as $user) {
            $day = $days[$user->id][$today] ?? null;
            $previous = $days[$user->id][$yesterday] ?? null;

            if ($day !== null && $this->dueClockIn($day, $local) && $this->send($user, $today, ClockReminder::CLOCK_IN)) {
                $sent++;
            }

            if ($day !== null && $this->dueClockOut($day, $local) && $this->send($user, $today, ClockReminder::CLOCK_OUT)) {
                $sent++;
            }

            if ($previous !== null && $this->dueUnclosed($previous, $local) && $this->send($user, $yesterday, ClockReminder::UNCLOSED)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array{employed: bool, capacity_minutes: int, absence: array{type: string|null, partial_minutes: int|null}|null, workdays: list<mixed>, schedule: array{start_from: string|null, start_to: string|null, expected_pause_minutes: int}|null}  $day
     */
    private function dueClockIn(array $day, CarbonImmutable $local): bool
    {
        if (! $day['employed'] || $day['capacity_minutes'] <= 0 || $day['workdays'] !== []) {
            return false;
        }

        $limit = $local->setTimeFromTimeString($day['schedule']['start_to'] ?? self::DEFAULT_START_TO)->addMinutes(self::GRACE_IN_MINUTES);

        return $local->greaterThanOrEqualTo($limit) && $local->lessThan($limit->addMinutes(self::WINDOW_IN_MINUTES));
    }

    /**
     * @param  array{expected_minutes: int, capacity_minutes: int, in_progress: bool, workdays: list<array{clock_in: string, open: bool, stale: bool}>, pause_minutes: int, schedule: array{start_from: string|null, start_to: string|null, expected_pause_minutes: int}|null}  $day
     */
    private function dueClockOut(array $day, CarbonImmutable $local): bool
    {
        if (! $day['in_progress'] || $day['capacity_minutes'] <= 0 || $day['workdays'] === []) {
            return false;
        }

        $first = CarbonImmutable::parse($day['workdays'][0]['clock_in']);
        $pause = max($day['schedule']['expected_pause_minutes'] ?? 0, $day['pause_minutes']);
        $expectedEnd = $first->addMinutes($day['capacity_minutes'] + $pause + self::GRACE_OUT_MINUTES);

        return $local->greaterThanOrEqualTo($expectedEnd);
    }

    /**
     * @param  array{incidents: list<string>}  $day
     */
    private function dueUnclosed(array $day, CarbonImmutable $local): bool
    {
        if ($local->format('H:i') < self::UNCLOSED_FROM) {
            return false;
        }

        return array_intersect($day['incidents'], ['missing_clock_out', 'no_records']) !== [];
    }

    /**
     * Reclama el aviso (inserción única) y lo envía; si el envío falla, lo libera.
     */
    private function send(User $user, string $date, string $type): bool
    {
        $notification = ClockReminder::make($type, $date);
        $kind = $notification->kind();

        if (($this->preferences->channelsFor($user, $kind) ?? []) === []) {
            return false;
        }

        $claimed = DB::table('clock_reminders')->insertOrIgnore([
            'user_id' => $user->id,
            'date' => $date,
            'kind' => $type,
            'sent_at' => now(),
        ]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            DB::table('clock_reminders')->where('user_id', $user->id)->where('date', $date)->where('kind', $type)->delete();

            throw $exception;
        }

        return true;
    }
}
