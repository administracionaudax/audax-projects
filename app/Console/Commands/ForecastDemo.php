<?php

namespace App\Console\Commands;

use App\Domain\Forecast\AllocationWriter;
use App\Domain\Forecast\ForecastProjectWriter;
use App\Enums\ProjectStatus;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Datos de ejemplo de la Previsión para enseñarla al equipo (D-262): con la gente, los
 * departamentos y algunos proyectos reales, unas asignaciones y tres previstos. Todo va marcado con
 * «[Ejemplo]» (el nombre de los previstos y la nota de cada asignación) y se borra entero con
 * --borrar. No es el DemoDataSeeder (que nunca va al servidor): no crea personas, clientes ni
 * proyectos, y no toca horas, tareas ni nada más.
 */
class ForecastDemo extends Command
{
    public const string MARK = '[Ejemplo]';

    protected $signature = 'app:forecast-demo
        {--borrar : Borra todos los datos de ejemplo de la Previsión}
        {--como= : Email de quien figura como autor (por defecto, el primer admin)}';

    protected $description = 'Crea (o borra con --borrar) datos de ejemplo de la Previsión, marcados con [Ejemplo]';

    public function handle(AllocationWriter $allocations, ForecastProjectWriter $forecasts): int
    {
        if ($this->option('borrar')) {
            [$forecastCount, $allocationCount] = $this->wipe();
            $this->components->info("Borrados {$forecastCount} previstos y {$allocationCount} asignaciones de ejemplo.");

            return self::SUCCESS;
        }

        if (ForecastProject::withTrashed()->where('name', 'like', self::MARK.'%')->exists()
            || Allocation::withTrashed()->where('note', 'like', self::MARK.'%')->exists()) {
            $this->components->error('Ya hay datos de ejemplo. Bórralos antes con --borrar.');

            return self::FAILURE;
        }

        $by = $this->author();
        $people = $this->peopleByDepartment();
        $departments = Department::query()->whereIn('id', $people->keys())->get()->keyBy('id');
        $projects = $this->busyProjects(4);

        if ($by === null || $people->isEmpty() || $projects->count() < 2) {
            $this->components->error('Faltan personas con departamento o proyectos activos para montar el ejemplo.');

            return self::FAILURE;
        }

        $monday = CarbonImmutable::now('Europe/Madrid')->startOfWeek();
        $date = fn (CarbonImmutable $day): string => $day->toDateString();
        $until = fn (int $weeks): string => $date($monday->addWeeks($weeks)->subDays(3));
        $note = fn (string $text): string => self::MARK.' '.$text;
        $pick = fn (int $department, int $index): ?User => $people->get($department)?->values()->get($index);

        DB::transaction(function () use ($allocations, $forecasts, $by, $people, $departments, $projects, $monday, $date, $until, $note, $pick): void {
            $deptIds = $people->keys()->values();
            $first = $deptIds->get(0);
            $second = $deptIds->get(1) ?? $first;
            $third = $deptIds->get(2) ?? $first;
            $allocate = fn (Project|ForecastProject $container, array $data) => $allocations->create($container, $data, $by);

            // Plan en proyectos reales (capa «Real»): quien va holgado, quien va justo y quien se pasa.
            $plan = [
                [$pick($first, 0), ['mode' => 'percent', 'percent' => 50, 'start_date' => $date($monday), 'end_date' => $until(8)]],
                [$pick($first, 1), ['mode' => 'per_day', 'minutes' => 180, 'start_date' => $date($monday), 'end_date' => $until(10)]],
                [$pick($first, 2), ['mode' => 'per_day', 'minutes' => 300, 'start_date' => $date($monday), 'end_date' => $until(6)]],
                [$pick($second, 0), ['mode' => 'per_day', 'minutes' => 240, 'start_date' => $date($monday), 'end_date' => $until(6)]],
                [$pick($second, 1), ['mode' => 'percent', 'percent' => 40, 'start_date' => $date($monday), 'end_date' => $until(12)]],
                [$pick($third, 0), ['mode' => 'percent', 'percent' => 60, 'start_date' => $date($monday), 'end_date' => $until(12)]],
                [$pick($third, 1), ['mode' => 'per_day', 'minutes' => 210, 'start_date' => $date($monday), 'end_date' => $until(12)]],
            ];

            foreach ($plan as $i => [$person, $data]) {
                if ($person !== null) {
                    $allocate($projects[$i % $projects->count()], [...$data, 'user_id' => $person->id, 'note' => $note('Plan de trabajo')]);
                }
            }

            // Un fee de un departamento sin persona (hueco), por meses.
            $allocate($projects->last(), ['department_id' => $third, 'mode' => 'monthly', 'minutes' => 20 * 60, 'start_date' => $date($monday->startOfMonth()), 'end_date' => $until(12), 'note' => $note('Fee mensual')]);

            // Colaboradores externos con departamento: unas horas en total.
            $external = User::role('collaborator')->where('is_active', true)->whereNotNull('department_id')->first();
            if ($external !== null) {
                $allocate($projects->first(), ['user_id' => $external->id, 'mode' => 'total', 'minutes' => 40 * 60, 'start_date' => $date($monday->addWeeks(2)), 'end_date' => $until(6), 'note' => $note('Apoyo externo')]);
            }

            // Posible, de un cliente que aún no existe: huecos de dos departamentos y una persona.
            $start = $monday->addWeeks(4);
            $web = $forecasts->create(['name' => self::MARK.' Web y branding', 'prospect_name' => 'Cliente de ejemplo', 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(7)->subDays(3)), 'estimated_minutes' => 250 * 60, 'description' => 'Datos de ejemplo para enseñar la Previsión.'], $by);
            $allocate($web, ['department_id' => $first, 'mode' => 'total', 'minutes' => 80 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(4)->subDays(3)), 'note' => $note('Diseño')]);
            $allocate($web, ['department_id' => $second, 'mode' => 'total', 'minutes' => 120 * 60, 'start_date' => $date($start->addWeeks(4)), 'end_date' => $date($start->addWeeks(7)->subDays(3)), 'note' => $note('Desarrollo')]);
            if ($person = $pick($first, 0)) {
                $allocate($web, ['user_id' => $person->id, 'mode' => 'percent', 'percent' => 40, 'start_date' => $date($start->addWeek()), 'end_date' => $date($start->addWeeks(7)->subDays(3)), 'note' => $note('Dirección de arte')]);
            }

            // Seguro: carga a una persona que ya va justa (se ve la sobrecarga).
            $start = $monday->addWeeks(3);
            $app = $forecasts->create(['name' => self::MARK.' App de reservas', 'prospect_name' => 'Cliente de ejemplo 2', 'confidence' => 'firm', 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(9)->subDays(3)), 'estimated_minutes' => 300 * 60, 'description' => 'Datos de ejemplo para enseñar la Previsión.'], $by);
            if ($person = $pick($second, 0)) {
                $allocate($app, ['user_id' => $person->id, 'mode' => 'per_day', 'minutes' => 240, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(9)->subDays(3)), 'note' => $note('Desarrollo')]);
            }
            $allocate($app, ['department_id' => $first, 'mode' => 'monthly', 'minutes' => 30 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(9)->subDays(3)), 'note' => $note('Diseño')]);

            // Posible: una campaña con un hueco y una persona de otro departamento.
            $start = $monday->addWeeks(6);
            $campaign = $forecasts->create(['name' => self::MARK.' Campaña de verano', 'prospect_name' => 'Cliente de ejemplo 3', 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3)), 'estimated_minutes' => 150 * 60, 'description' => 'Datos de ejemplo para enseñar la Previsión.'], $by);
            $allocate($campaign, ['department_id' => $third, 'mode' => 'total', 'minutes' => 90 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3)), 'note' => $note($departments[$third]->name ?? 'Campaña')]);
            if ($person = $pick($third, 1)) {
                $allocate($campaign, ['user_id' => $person->id, 'mode' => 'percent', 'percent' => 30, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3)), 'note' => $note('Contenidos')]);
            }
        });

        $this->components->info('Datos de ejemplo de la Previsión creados (marcados con «'.self::MARK.'»). Para borrarlos: php artisan app:forecast-demo --borrar');

        return self::SUCCESS;
    }

    /**
     * @return array{int, int}
     */
    private function wipe(): array
    {
        return DB::transaction(function (): array {
            $forecastIds = ForecastProject::withTrashed()->where('name', 'like', self::MARK.'%')->pluck('id');
            $allocationCount = Allocation::withTrashed()
                ->where(fn ($query) => $query->where('note', 'like', self::MARK.'%')->orWhereIn('forecast_project_id', $forecastIds))
                ->forceDelete();
            $forecastCount = ForecastProject::withTrashed()->whereIn('id', $forecastIds)->forceDelete();

            return [(int) $forecastCount, (int) $allocationCount];
        });
    }

    private function author(): ?User
    {
        $email = $this->option('como');

        return is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : User::role('admin')->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Plantilla activa con departamento (sin colaboradores externos ni clientes), por departamento,
     * de los departamentos con más gente a los de menos.
     *
     * @return Collection<int, Collection<int, User>>
     */
    private function peopleByDepartment(): Collection
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereNull('client_id')
            ->whereNotNull('department_id')
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'collaborator'))
            ->orderBy('name')
            ->get();

        /** @var array<int, list<User>> $groups */
        $groups = [];

        foreach ($users as $user) {
            $groups[(int) $user->department_id][] = $user;
        }

        uasort($groups, fn (array $a, array $b): int => count($b) <=> count($a));

        /** @var Collection<int, Collection<int, User>> $result */
        $result = new Collection;

        foreach ($groups as $department => $members) {
            $result->put($department, new Collection($members));
        }

        return $result;
    }

    /**
     * Proyectos activos de clientes con más horas en los dos últimos meses (los que más se mueven).
     *
     * @return Collection<int, Project>
     */
    private function busyProjects(int $limit): Collection
    {
        $ids = TimeEntry::query()
            ->where('date', '>=', now()->subMonths(2)->toDateString())
            ->whereNotNull('project_id')
            ->selectRaw('project_id, sum(minutes) as total')
            ->groupBy('project_id')
            ->orderByDesc('total')
            ->limit(30)
            ->pluck('project_id');

        return Project::query()
            ->whereIn('id', $ids)
            ->whereNotNull('client_id')
            ->where('status', ProjectStatus::Active)
            ->get()
            ->sortBy(fn (Project $project) => $ids->search($project->id))
            ->filter(fn (Project $project) => AllocationWriter::editable($project))
            ->take($limit)
            ->values();
    }
}
