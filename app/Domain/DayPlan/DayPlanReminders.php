<?php

namespace App\Domain\DayPlan;

use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DayPlan\DayPlanReminder;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recordatorio del plan del día (docs/PLAN-CARGAS.md §9 y §15, P2: a las 8:30; D-252).
 *
 * - **Cuándo:** a la hora límite de Madrid (`day_plan_deadline`, 08:30) y durante las tres horas
 *   siguientes (el comando corre cada 5 minutos: si el servidor estuvo parado a las 8:30, sale en
 *   cuanto vuelve, pero nunca a media tarde). Con el ajuste `day_plan_reminder_enabled` apagado o
 *   el módulo apagado de verdad (también en modo de prueba, D-239), no sale.
 * - **A quién:** la plantilla del plan del día sin ninguna línea ese día, solo en sus días con
 *   jornada: nunca en fin de semana (según su horario), festivo, ausencia aprobada de día completo
 *   ni con «Estoy fuera». Por los canales que tenga activados (app y navegador por defecto, email
 *   si lo activa); sin ninguno, no se le avisa.
 * - **Una sola vez por persona y día local**, se ejecute el comando las veces que sea y cambie la
 *   hora (el día es el de Madrid, no el UTC): se reclama con `day_plans.reminded_at` en una
 *   actualización atómica. Si el envío falla, se libera para el siguiente intento.
 * - **«Recordar» a mano** (su responsable o un admin desde «Equipo hoy»): el mismo aviso con su
 *   nombre y el mismo registro (tampoco dos el mismo día). En modo de prueba no avisa a nadie.
 */
final class DayPlanReminders
{
    /** Minutos después de la hora límite en los que aún sale el recordatorio. */
    public const int WINDOW_MINUTES = 180;

    public function __construct(
        private readonly DayPlanTeam $team,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * Envía los recordatorios que tocan ahora. Devuelve cuántos.
     */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        if (! (bool) Setting::get('day_plan_reminder_enabled', true) || ! AppModules::enabled(AppModule::DayPlan)) {
            return 0;
        }

        $now ??= CarbonImmutable::now();
        $date = LocalTime::dateOf($now);
        $deadline = DayPlanCalendar::deadlineOn($date);

        if ($now < $deadline || $now >= $deadline->addMinutes(self::WINDOW_MINUTES)) {
            return 0;
        }

        $people = $this->team->people();
        $ids = $people->modelKeys();
        $skip = array_flip([
            ...DayPlanItem::query()->whereIn('user_id', $ids)->where('date', $date)->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            ...DayPlan::query()->whereIn('user_id', $ids)->where('date', $date)->whereNotNull('reminded_at')->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
        ]);
        $candidates = $people->reject(fn (User $user): bool => isset($skip[$user->id]))->values();
        $day = CarbonImmutable::parse($date);
        $days = $this->team->days($candidates, $day, $day);
        $sent = 0;

        foreach ($candidates as $user) {
            $info = $days[$user->id][$date] ?? null;

            if ($info === null || ! DayPlanTeam::works($info) || ! $this->wants($user)) {
                continue;
            }

            if ($this->send($user, $date, new DayPlanReminder($date))) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * «Recordar» a mano. Resultado: sent, preview (modo de prueba), has_plan, off (hoy no trabaja),
     * already (ya se le ha recordado hoy) o no_channel.
     */
    public function remind(User $by, User $person): string
    {
        $date = LocalTime::todayString();

        if (DayPlanItem::query()->where('user_id', $person->id)->where('date', $date)->exists()) {
            return 'has_plan';
        }

        $day = CarbonImmutable::parse($date);
        $info = $this->team->days(new Collection([$person]), $day, $day)[$person->id][$date] ?? null;

        if ($info === null || ! DayPlanTeam::works($info)) {
            return 'off';
        }

        if (! AppModules::enabled(AppModule::DayPlan)) {
            return 'preview';
        }

        if (! $this->wants($person)) {
            return 'no_channel';
        }

        return $this->send($person, $date, new DayPlanReminder($date, $by->name)) ? 'sent' : 'already';
    }

    /** ¿Ya se le ha recordado ese día? */
    public static function reminded(int $userId, string $date): bool
    {
        return DayPlan::query()->where('user_id', $userId)->where('date', $date)->whereNotNull('reminded_at')->exists();
    }

    private function wants(User $user): bool
    {
        return ($this->preferences->channelsFor($user, 'day_plan.reminder') ?? []) !== [];
    }

    /**
     * Reclama el recordatorio del día (atómico) y lo envía; si el envío falla, lo libera.
     */
    private function send(User $user, string $date, DayPlanReminder $notification): bool
    {
        DayPlan::query()->insertOrIgnore([
            'user_id' => $user->id,
            'date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimed = DB::table('day_plans')
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->whereNull('reminded_at')
            ->update(['reminded_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            DB::table('day_plans')->where('user_id', $user->id)->where('date', $date)->update(['reminded_at' => null]);

            throw $exception;
        }

        return true;
    }
}
