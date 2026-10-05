<?php

namespace App\Console\Commands;

use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Enums\AppModule;
use App\Models\WeeklyCycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recordatorios de la weekly por reglas (F-101, F-102, F-106 y F-107, D-199): lo lanza el
 * planificador cada 5 minutos (routes/console.php), como la GitHub Action de WeeklySync que llamaba
 * a check-scheduled-reminders. Con la semana activa y el módulo encendido, cada regla activa cuyo
 * día y hora de Madrid han llegado en los últimos 10 minutos avisa por su canal a quien aún debe
 * enviar la weekly (WeeklyReminderRecipients: sin exentos, activos y nunca colaboradores externos).
 * Un mismo disparo no llega dos veces (weekly_reminder_logs, WeeklyNotifier).
 */
#[Signature('weeklies:remind')]
#[Description('Envía los recordatorios de la weekly cuyas reglas tocan ahora (cada 5 minutos)')]
class RemindWeeklies extends Command
{
    public function handle(WeeklyReminders $reminders): int
    {
        if (! AppModules::enabled(AppModule::Weeklies)) {
            $this->info('El módulo de la Weekly está desactivado.');

            return self::SUCCESS;
        }

        $cycle = WeeklyCycle::query()->active()->first();

        if ($cycle === null) {
            $this->info('No hay ninguna semana activa.');

            return self::SUCCESS;
        }

        $results = $reminders->runDueRules($cycle);

        if ($results === []) {
            $this->info('Ninguna regla toca ahora.');

            return self::SUCCESS;
        }

        foreach ($results as $item) {
            $rule = $item['rule'];
            $result = $item['result'];
            $this->info("Regla {$rule->id} ({$rule->channel->value}, día {$rule->day_of_week} a las {$rule->time}): {$result->notified} avisos, {$result->skipped} omitidos, {$result->duplicates} ya enviados.");
        }

        return self::SUCCESS;
    }
}
