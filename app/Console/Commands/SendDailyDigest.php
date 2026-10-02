<?php

namespace App\Console\Commands;

use App\Domain\Notifications\DailyDigest;
use App\Notifications\DailyDigestNotification;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resumen diario por email (SPEC §13, D-073): a las 08:00 de Madrid (routes/console.php), a cada
 * persona interna y activa con el resumen activado, un email con sus avisos sin leer de las últimas
 * config('notifications.daily_digest.hours') horas cuyo email quiere (App\Domain\Notifications\
 * DailyDigest). Si no hay ninguno, no se envía nada.
 *
 * Para no repetir si el comando se ejecuta dos veces el mismo día, cada envío se reclama con
 * Cache::add (atómico en Valkey) por persona y día de Madrid antes de encolarlo, hasta pasado ese
 * día; si encolarlo falla, se suelta para poder reintentar. Es el mismo mecanismo que
 * reports:weekly-digest y app:notify-due-tasks. El email va por la cola `mail`.
 */
#[Signature('notifications:daily-digest')]
#[Description('Envía el resumen diario por email de los avisos sin leer a quien lo tiene activado')]
class SendDailyDigest extends Command
{
    /**
     * Clave con la que una ejecución reclama el resumen de una persona para un día (Y-m-d de Madrid).
     */
    public static function claimKey(int $userId, string $date): string
    {
        return "notifications-daily-digest:{$userId}:{$date}";
    }

    public function handle(DailyDigest $digest): int
    {
        $today = LocalTime::today();
        $hours = max((int) config('notifications.daily_digest.hours', 24), 1);
        $perGroup = max((int) config('notifications.daily_digest.items_per_group', 10), 1);
        // Hasta pasado el día de hoy en Madrid (con margen por el cambio de hora).
        $claimUntil = $today->addDay()->addHours(3);

        $recipients = $digest->recipients();
        // Desde el inicio del minuto: el programador lo lanza a las 08:00 y unos segundos de retraso
        // no deben dejar fuera (ni repetir) avisos entre un día y el siguiente.
        $digests = $digest->groupsFor($recipients, now()->startOfMinute()->subHours($hours), $perGroup);

        $sent = 0;

        foreach ($recipients as $recipient) {
            $groups = $digests[$recipient->id] ?? [];

            if ($groups === []) {
                continue;
            }

            $claim = self::claimKey($recipient->id, $today->toDateString());

            if (! Cache::add($claim, true, $claimUntil)) {
                continue;
            }

            try {
                $recipient->notify(new DailyDigestNotification($groups, $hours));
            } catch (Throwable $exception) {
                Cache::forget($claim);

                throw $exception;
            }

            $sent++;
        }

        $this->info("Resúmenes enviados: {$sent}. Con el resumen activado: {$recipients->count()}.");

        return self::SUCCESS;
    }
}
