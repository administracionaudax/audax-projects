<?php

namespace App\Console\Commands;

use App\Domain\Recurring\RecurringTaskGenerator;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:generate-recurring')]
#[Description('Crea las instancias de hoy (y las pendientes) de las tareas recurrentes')]
class GenerateRecurringTasks extends Command
{
    public function handle(RecurringTaskGenerator $generator): int
    {
        $created = $generator->generate(LocalTime::today());
        $this->components->info("Tareas recurrentes creadas: {$created}");

        return self::SUCCESS;
    }
}
