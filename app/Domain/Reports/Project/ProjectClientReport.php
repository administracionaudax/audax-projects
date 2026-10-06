<?php

namespace App\Domain\Reports\Project;

use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\EstimateComparison;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportScope;
use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Generator;

/**
 * Datos del informe de un proyecto «Para el cliente» (D-241): lo que vería el cliente en el portal
 * (SPEC §11, D-064), para enviárselo:
 * - solo las horas en los estados que ve su cliente (portal_entry_visibility: por defecto,
 *   aprobadas y bloqueadas; nunca borradores), con los filtros del informe y lo que puede ver quien
 *   lo genera (ReportScope, D-044),
 * - las personas como las ve el cliente (portal_person_display: nombre, iniciales o «Equipo»),
 * - las bolsas con las cifras del portal (PortalBankFigures: dentro, exceso, saldo y estado
 *   repartidos solo entre las horas que ve, D-092 y D-093),
 * - nunca costes, tarifas, importes, márgenes, estimaciones ni comentarios internos.
 * Un proyecto interno (sin cliente) usa lo que el portal tiene por defecto. Sin caché: solo lo
 * piden las exportaciones. Un número fijo de consultas.
 *
 * @phpstan-import-type MonthRow from ProjectReportSections
 * @phpstan-import-type EntryRow from ProjectReportSections
 *
 * @phpstan-type HoursRow array{name: string, minutes: int}
 * @phpstan-type TaskRow array{id: int, parent_id: int|null, depth: int, title: string, parent: string|null, minutes: int}
 * @phpstan-type ClientBank array{id: int, name: string, status: string, start_date: string, end_date: string|null, total_minutes: int,
 *     within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float, period_minutes: int}
 * @phpstan-type ClientData array{period_minutes: int, lifetime_minutes: int, by_person: list<HoursRow>, by_type: list<HoursRow>,
 *     tasks: list<TaskRow>, weekly: list<array{week: string, minutes: int}>, monthly: list<array{month: string, minutes: int}>,
 *     banks: list<ClientBank>, entry_count: int}
 */
final class ProjectClientReport
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly ProjectReportSections $sections,
    ) {}

    /**
     * El alcance de quien genera el informe, solo con las horas que ve el cliente y sin datos
     * económicos.
     */
    public static function scope(ReportScope $scope, Project $project): ReportScope
    {
        return $scope->withFilters($scope->filters->withStatuses(self::visibility($project)->statuses()))->withoutFinancials();
    }

    public static function display(Project $project): PortalPersonDisplay
    {
        return self::client($project)->portal_person_display ?? PortalPersonDisplay::Name;
    }

    public static function visibility(Project $project): PortalEntryVisibility
    {
        return self::client($project)->portal_entry_visibility ?? PortalEntryVisibility::Approved;
    }

    /**
     * Nombre de una persona tal como la ve el cliente.
     */
    public static function personLabel(Project $project, string $name): string
    {
        return PortalScope::label(self::display($project), (new User)->forceFill(['name' => $name]));
    }

    /**
     * @return ClientData
     */
    public function data(ReportScope $scope, Project $project): array
    {
        $client = self::scope($scope, $project);
        $period = $this->metrics->hours($client);

        return [
            'period_minutes' => $period['logged_minutes'],
            'lifetime_minutes' => (int) (clone EstimateComparison::lifetime($client)->entries())->toBase()->sum('time_entries.minutes'),
            'by_person' => $this->people($project, $this->metrics->breakdown($client, Dimension::Person)),
            'by_type' => array_map(fn (array $row): array => ['name' => $row['name'], 'minutes' => $row['logged_minutes']],
                $this->metrics->breakdown($client, Dimension::TaskType)),
            'tasks' => $this->tasks($this->sections->periodByTask($client)),
            'weekly' => $this->weekly($client),
            'monthly' => array_map(fn (array $month): array => ['month' => $month['month'], 'minutes' => $month['logged_minutes']],
                $this->sections->monthly($client)),
            'banks' => $this->banks($client, $project),
            'entry_count' => $this->sections->entryCount($client),
        ];
    }

    /**
     * Las entradas que ve el cliente, con las personas como las ve él.
     *
     * @return Generator<int, EntryRow>
     */
    public function entries(ReportScope $scope, Project $project, ?int $limit = null): Generator
    {
        $display = self::display($project);
        /** @var array<string, string> $labels */
        $labels = [];

        foreach ($this->sections->entries(self::scope($scope, $project), $limit) as $entry) {
            $entry['person'] = $labels[$entry['person']] ??= PortalScope::label($display, (new User)->forceFill(['name' => $entry['person']]));

            yield $entry;
        }
    }

    /**
     * Horas por persona con el nombre que ve el cliente: con iniciales repetidas o «Equipo», las
     * filas con el mismo nombre se juntan.
     *
     * @param  list<array{name: string, logged_minutes: int}>  $rows
     * @return list<HoursRow>
     */
    private function people(Project $project, array $rows): array
    {
        $byLabel = [];
        foreach ($rows as $row) {
            $label = self::personLabel($project, $row['name']);
            $byLabel[$label] = ($byLabel[$label] ?? 0) + $row['logged_minutes'];
        }

        $people = [];
        foreach ($byLabel as $name => $minutes) {
            $people[] = ['name' => (string) $name, 'minutes' => $minutes];
        }
        usort($people, fn (array $a, array $b): int => [$b['minutes'], $a['name']] <=> [$a['minutes'], $b['name']]);

        return $people;
    }

    /**
     * Horas del periodo por tarea: cada tarea principal con sus horas y las de sus subtareas, y
     * debajo las subtareas con horas (SPEC §6). Por horas (desc). Una consulta (las tareas, también
     * las borradas, y sus tareas principales).
     *
     * @param  array<int, int>  $minutesByTask
     * @return list<TaskRow>
     */
    private function tasks(array $minutesByTask): array
    {
        if ($minutesByTask === []) {
            return [];
        }

        $tasks = Task::query()->withTrashed()
            ->whereKey(array_keys($minutesByTask))
            ->orWhereIn('id', Task::query()->withTrashed()->select('parent_task_id')->whereKey(array_keys($minutesByTask))->whereNotNull('parent_task_id'))
            ->get(['id', 'parent_task_id', 'title', 'position'])
            ->keyBy('id');

        /** @var array<int, array{root: Task|null, own: int, children: list<array{task: Task, minutes: int}>, title: string}> $groups */
        $groups = [];
        foreach ($minutesByTask as $taskId => $minutes) {
            $task = $tasks->get($taskId);
            $parent = $task?->parent_task_id !== null ? $tasks->get($task->parent_task_id) : null;

            if ($task !== null && $parent !== null) {
                $groups[$parent->id] ??= ['root' => $parent, 'own' => 0, 'children' => [], 'title' => $parent->title];
                $groups[$parent->id]['children'][] = ['task' => $task, 'minutes' => $minutes];
            } else {
                $groups[$taskId] ??= ['root' => $task, 'own' => 0, 'children' => [], 'title' => $task->title ?? '—'];
                $groups[$taskId]['own'] += $minutes;
            }
        }

        $totals = [];
        foreach ($groups as $id => $group) {
            $totals[$id] = $group['own'] + array_sum(array_column($group['children'], 'minutes'));
        }
        uksort($groups, fn (int $a, int $b): int => [$totals[$b], $groups[$a]['title']] <=> [$totals[$a], $groups[$b]['title']]);

        $rows = [];
        foreach ($groups as $id => $group) {
            $rows[] = ['id' => $id, 'parent_id' => null, 'depth' => 0, 'title' => $group['title'], 'parent' => null, 'minutes' => $totals[$id]];
            $children = $group['children'];
            usort($children, fn (array $a, array $b): int => [(int) $a['task']->position, $a['task']->id] <=> [(int) $b['task']->position, $b['task']->id]);
            foreach ($children as $child) {
                $rows[] = ['id' => $child['task']->id, 'parent_id' => $id, 'depth' => 1, 'title' => $child['task']->title, 'parent' => $group['title'], 'minutes' => $child['minutes']];
            }
        }

        return $rows;
    }

    /**
     * Semanas (lunes) del periodo sin huecos, con sus horas.
     *
     * @return list<array{week: string, minutes: int}>
     */
    private function weekly(ReportScope $scope): array
    {
        $byWeek = [];
        foreach ($this->metrics->breakdown($scope, Dimension::Week) as $row) {
            $byWeek[substr((string) $row['key'], 0, 10)] = $row['logged_minutes'];
        }

        return array_map(fn (string $week): array => ['week' => $week, 'minutes' => $byWeek[$week] ?? 0], ProjectReportSections::weeks($scope->filters));
    }

    /**
     * Bolsas del proyecto con las cifras del portal (toda la vida de la bolsa) y las horas visibles
     * del periodo. Sin cliente (proyecto interno), sin bolsas que enseñar.
     *
     * @return list<ClientBank>
     */
    private function banks(ReportScope $scope, Project $project): array
    {
        $client = self::client($project);
        if ($client === null) {
            return [];
        }

        $banks = HourBank::query()
            ->where('project_id', $project->id)
            ->when($scope->filters->bankIds !== [], fn ($query) => $query->whereIn('id', $scope->filters->bankIds))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'status', 'start_date', 'end_date', 'total_minutes']);

        if ($banks->isEmpty()) {
            return [];
        }

        $figures = PortalBankFigures::many(PortalScope::forClient($client), $banks);
        $period = (clone $scope->entries())->toBase()
            ->whereIn('time_entries.hour_bank_id', $banks->modelKeys())
            ->selectRaw('time_entries.hour_bank_id as bank_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.hour_bank_id')
            ->pluck('minutes', 'bank_id');

        return array_values($banks->map(function (HourBank $bank) use ($figures, $period): array {
            $figure = $figures[$bank->id];

            return [
                'id' => $bank->id,
                'name' => $bank->name,
                'status' => PortalBankFigures::status($bank, $figure)->value,
                'start_date' => $bank->start_date->toDateString(),
                'end_date' => $bank->end_date?->toDateString(),
                'total_minutes' => $figure['total_minutes'],
                'within_minutes' => $figure['within_minutes'],
                'overage_minutes' => $figure['overage_minutes'],
                'remaining_minutes' => $figure['remaining_minutes'],
                'percent' => $figure['percent'],
                'period_minutes' => (int) ($period[$bank->id] ?? 0),
            ];
        })->all());
    }

    /**
     * El cliente del proyecto con sus ajustes del portal (cargado una vez, también si está borrado).
     */
    private static function client(Project $project): ?Client
    {
        if ($project->client_id === null) {
            return null;
        }

        if (! $project->relationLoaded('portalClient')) {
            $project->setRelation('portalClient', Client::query()->withTrashed()
                ->find($project->client_id, ['id', 'name', 'portal_person_display', 'portal_entry_visibility']));
        }

        $client = $project->getRelation('portalClient');

        return $client instanceof Client ? $client : null;
    }
}
