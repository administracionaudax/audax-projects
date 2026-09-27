<?php

namespace App\Console\Commands;

use App\Domain\Reports\WeeklyDigest;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Reports\WeeklyDigestNotification;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resumen semanal de productividad por email (SPEC §10, D-047): los lunes a las 08:00 de Madrid
 * (routes/console.php), sobre la semana anterior,
 * - a cada responsable de departamento, sobre su equipo,
 * - a cada admin, sobre toda la agencia.
 * No se envía si no hay nada que contar ni si el ajuste weekly_digest_enabled está desactivado.
 *
 * Para no repetir si el comando se ejecuta dos veces la misma semana, cada envío se reclama con
 * Cache::add (atómico) por persona y semana antes de encolarlo. La notificación va por cola (el
 * email por la cola `mail`).
 */
#[Signature('reports:weekly-digest')]
#[Description('Envía el resumen semanal de productividad a responsables y admins (semana anterior)')]
class SendWeeklyDigest extends Command
{
    /** Cuánto dura la reclamación de un envío: más de una semana, para no repetirlo. */
    public const int CLAIM_DAYS = 8;

    public static function claimKey(int $userId, string $weekStart): string
    {
        return "reports-weekly-digest:{$userId}:{$weekStart}";
    }

    public function handle(WeeklyDigest $digests): int
    {
        if (! (bool) Setting::get('weekly_digest_enabled', true)) {
            $this->info('El resumen semanal está desactivado en los ajustes.');

            return self::SUCCESS;
        }

        $today = LocalTime::today();
        $week = WeeklyDigest::previousWeek($today);
        $weekStart = $week->from->toDateString();

        $recipients = User::query()
            ->active()
            ->internal()
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('name', Role::Admin->value))
                ->orWhereHas('managedDepartments'))
            ->orderBy('id')
            ->get();

        $sent = 0;
        $empty = 0;

        foreach ($recipients as $recipient) {
            if (! WeeklyDigest::receives($recipient)) {
                continue;
            }

            $digest = $digests->for($recipient, $week, $today);

            if (WeeklyDigest::isEmpty($digest)) {
                $empty++;

                continue;
            }

            $claim = self::claimKey($recipient->id, $weekStart);

            if (! Cache::add($claim, true, $today->addDays(self::CLAIM_DAYS))) {
                continue;
            }

            try {
                $recipient->notify(new WeeklyDigestNotification($digest));
            } catch (Throwable $exception) {
                Cache::forget($claim);

                throw $exception;
            }

            $sent++;
        }

        $this->info("Resúmenes enviados: {$sent}. Sin nada que contar: {$empty}.");

        return self::SUCCESS;
    }
}
