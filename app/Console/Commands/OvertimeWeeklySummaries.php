<?php

namespace App\Console\Commands;

use App\Domain\People\OvertimeService;
use App\Domain\People\PeopleAccess;
use App\Domain\People\PeopleNotifier;
use App\Domain\Time\Week;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Notifications\People\OvertimeWeeklySummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Resumen semanal de horas extra (art. 35.5 ET; convenio de publicidad: totalización semanal con
 * copia a la persona; D-349): los lunes a las 08:00 de Madrid, a cada persona con horas extra o
 * complementarias reconocidas o exceso sin clasificar la semana anterior. Nada con el módulo
 * apagado.
 */
#[Signature('people:overtime-summary {--semana= : Semana ISO (AAAA-Www); por defecto, la anterior}')]
#[Description('Envía el resumen semanal de horas extra del registro de jornada')]
class OvertimeWeeklySummaries extends Command
{
    public function handle(OvertimeService $overtime): int
    {
        if (! AppModules::enabled(AppModule::People)) {
            $this->info('Módulo Personas apagado: no se envía nada.');

            return self::SUCCESS;
        }

        $week = Week::fromIso((string) $this->option('semana')) ?? Week::current()->previous();
        $sent = 0;

        foreach (PeopleAccess::registerSubjects()->active()->get() as $user) {
            $summary = $overtime->weekSummary($user, $week);

            if ($summary['days'] === []) {
                continue;
            }

            PeopleNotifier::send([$user], new OvertimeWeeklySummary($summary, $week->label(), $overtime->yearMinutes($user->id, (int) $week->start->year)));
            $sent++;
        }

        $this->info("Resúmenes de horas extra enviados: {$sent}.");

        return self::SUCCESS;
    }
}
