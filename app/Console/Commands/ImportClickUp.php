<?php

namespace App\Console\Commands;

use App\Domain\Admin\UserInviter;
use App\Domain\Import\ClickUp\ClickUpImporter;
use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Enums\Role;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Importa ClickUp (D-135 y D-136) desde un export de su API v2 en {ruta}: tree.json, tasks.json y
 * time_entries.json, más el fichero de personas (por defecto {ruta}/personas.json, fuera de Git).
 *
 * Formato de personas.json (App\Domain\Import\ClickUp\PeopleFile):
 *
 *   {
 *     "default_manager": "alfredo@empresa.es",
 *     "people": [
 *       {"clickup_email": "ana@empresa.es", "email": "ana@empresa.es", "name": "Ana Pérez",
 *        "role": "department_manager", "department": "Diseño", "is_department_manager": true,
 *        "import": true}
 *     ]
 *   }
 *
 *   - role: admin, department_manager, employee o collaborator,
 *   - department: nombre (se crea si no existe) o null,
 *   - is_department_manager: responsable de su departamento (solo admin o department_manager),
 *   - import: false para dejar fuera a alguien (ni cuenta, ni tareas, ni horas),
 *   - default_manager: gestor principal de los proyectos sin admin ni responsable con horas.
 *
 * Idempotente: se puede repetir para traer lo último de ClickUp. --dry-run lo hace todo dentro de
 * una transacción que se deshace y muestra el informe. No envía nada salvo con --invitar (y nunca
 * en --dry-run): la invitación a las personas importadas que aún no han entrado nunca.
 */
#[Signature('app:import-clickup
    {ruta : Carpeta del export de ClickUp (tree.json, tasks.json y time_entries.json)}
    {--personas= : Fichero de personas (por defecto, {ruta}/personas.json)}
    {--dry-run : Simula la importación y deshace todo al terminar}
    {--invitar : Envía al final la invitación a las personas importadas que aún no han entrado}')]
#[Description('Importa clientes, proyectos, bolsas, tareas y horas de un export de ClickUp')]
class ImportClickUp extends Command
{
    public function handle(ClickUpImporter $importer, UserInviter $inviter): int
    {
        $directory = rtrim((string) $this->argument('ruta'), '/');
        $peoplePath = (string) ($this->option('personas') ?: $directory.'/personas.json');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $people = PeopleFile::load($peoplePath);
        } catch (ValidationException $e) {
            $this->components->error('El fichero de personas no es válido:');
            foreach ($e->errors() as $field => $messages) {
                $this->line("  {$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Simulación de la importación de ClickUp (no se guarda nada)' : 'Importación de ClickUp');

        try {
            $report = $importer->run($directory, $people, $dryRun, $this->progress());
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        if ($this->option('invitar')) {
            $dryRun
                ? $this->components->warn('Con --dry-run no se envía ninguna invitación.')
                : $this->invite($people, $inviter);
        }

        return self::SUCCESS;
    }

    private function progress(): ImportOutput
    {
        $command = $this;

        return new class($command) implements ImportOutput
        {
            private ?ProgressBar $bar = null;

            public function __construct(private readonly ImportClickUp $command) {}

            public function stage(string $label): void
            {
                $this->command->getOutput()->writeln("  <fg=gray>›</> {$label}");
            }

            public function progressStart(int $max): void
            {
                $this->bar = $this->command->getOutput()->createProgressBar($max);
                $this->bar->start();
            }

            public function progressAdvance(int $steps = 1): void
            {
                $this->bar?->advance($steps);
            }

            public function progressFinish(): void
            {
                $this->bar?->finish();
                $this->bar = null;
                $this->command->getOutput()->newLine();
            }
        };
    }

    private function printReport(ImportReport $report): void
    {
        $this->newLine();
        $this->components->info($report->dryRun ? 'Informe (simulación: nada se ha guardado)' : 'Informe');

        $this->table(['Tipo', 'Creados', 'Actualizados', 'Sin cambios', 'Omitidos'], $report->rows());

        $rows = [];
        foreach ($report->minutesByPerson() as $name => $minutes) {
            $rows[] = [$name, Duration::format($minutes)];
        }
        $rows[] = ['Total', Duration::format($report->totalMinutes())];
        $this->table(['Persona', 'Horas importadas'], $rows);

        if ($report->discarded() !== []) {
            $this->line('  Registros de horas descartados:');
            foreach ($report->discarded() as $reason => $count) {
                $this->line("    - {$reason}: {$count}");
            }
        }

        if ($report->warnings() !== []) {
            $this->line('  Avisos:');
            foreach ($report->warnings() as $warning => $count) {
                $this->line('    - '.$warning.($count > 1 ? " ({$count})" : ''));
            }
        }

        $this->newLine();
        $this->line(sprintf('  Tiempo: %.1f s · Memoria máxima: %.0f MB', $report->seconds, $report->peakMemoryBytes / 1048576));
    }

    /**
     * Invitaciones (con --invitar): a las personas importadas, activas, que nunca han entrado.
     */
    private function invite(PeopleFile $people, UserInviter $inviter): void
    {
        $actor = User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();

        if ($actor === null) {
            $this->components->error('No hay ninguna cuenta de administración activa que firme las invitaciones.');

            return;
        }

        $sent = 0;
        foreach ($people->people as $person) {
            if (! $person->import) {
                continue;
            }

            $user = User::query()->whereRaw('lower(email) = ?', [$person->email])->where('is_active', true)->first();

            if ($user === null || LoginEvent::query()->where('user_id', $user->id)->where('succeeded', true)->exists()) {
                continue;
            }

            $inviter->send($actor, $user);
            $sent++;
        }

        $this->components->info("Invitaciones enviadas: {$sent}.");
    }
}
