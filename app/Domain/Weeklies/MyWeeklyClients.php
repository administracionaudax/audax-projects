<?php

namespace App\Domain\Weeklies;

use App\Domain\Weeklies\Tasks\TaskNotes;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Los clientes de «Mi weekly» (D-150, F-033, F-044, F-045 y F-048):
 * - propuestos: los de los proyectos (no archivados) de los que soy miembro o gestor, los clientes a
 *   los que me he unido en la Weekly (D-221) y los de los proyectos en los que he imputado horas esa
 *   semana (de lunes a domingo), más los que ya tienen un apunte en mi weekly,
 * - catálogo para «Añadir otro cliente»: los clientes activos, con sus proyectos abiertos (el
 *   proyecto del apunte es opcional), más los inactivos que ya tengan un apunte,
 * - «Autocompletar desde mis tareas y horas» (F-048): por cliente, las tareas en las que he imputado
 *   esa semana (con el tiempo) y mis tareas asignadas que he terminado o que vencen esa semana.
 * «General / Interno» (client_id nulo) va siempre aparte, en la interfaz.
 */
final class MyWeeklyClients
{
    /**
     * @return array{
     *     proposed: list<int>,
     *     catalog: list<array{id: int, name: string, icon: string|null, is_active: bool, projects: list<array{id: int, code: string, name: string, is_mine: bool}>}>
     * }
     */
    public function for(User $user, WeeklyCycle $cycle, ?WeeklySubmission $submission = null): array
    {
        [$from, $to] = $this->range($cycle);

        $memberProjectIds = $user->projects()->pluck('projects.id')->map(fn ($id): int => (int) $id)->all();
        $loggedProjectIds = TimeEntry::query()
            ->where('user_id', $user->id)
            ->between($from, $to)
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $proposedFromProjects = Project::query()
            ->whereKey(array_values(array_unique([...$memberProjectIds, ...$loggedProjectIds])))
            ->where(fn (Builder $query) => $query->notArchived()->orWhereIn('id', $loggedProjectIds))
            ->whereNotNull('client_id')
            ->pluck('client_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Los clientes a los que me he unido en la Weekly (D-221).
        $followedClientIds = (new WeeklyClientSubscriptions)->clientIds($user);

        $entryClientIds = $submission === null ? [] : $submission->entries()
            ->whereNotNull('client_id')
            ->pluck('client_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $clients = Client::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $entryClientIds))
            ->with(['projects' => fn ($projects) => $projects->notArchived()->orderBy('code')->select(['id', 'client_id', 'code', 'name'])])
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'is_active']);

        $known = $clients->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $proposed = array_values(array_intersect($known, array_unique([...$proposedFromProjects, ...$followedClientIds, ...$entryClientIds])));
        $mine = array_flip($memberProjectIds);

        return [
            'proposed' => $proposed,
            'catalog' => array_values($clients->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'icon' => $client->icon,
                'is_active' => $client->is_active,
                'projects' => array_values($client->projects->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'is_mine' => isset($mine[$project->id]),
                ])->all()),
            ])->all()),
        ];
    }

    /** Caracteres de la nota de una tarea en «Autocompletar». */
    public const int NOTE_LIMIT = 300;

    /**
     * Texto para «Autocompletar» por cliente (clave: id del cliente o «general»), una línea por tarea.
     *
     * @return array<int|string, string>
     */
    public function autofill(User $user, WeeklyCycle $cycle): array
    {
        [$from, $to] = $this->range($cycle);

        /** @var array<int, int> $minutesByTask */
        $minutesByTask = TimeEntry::query()
            ->where('user_id', $user->id)
            ->between($from, $to)
            ->selectRaw('task_id, SUM(minutes) as total')
            ->groupBy('task_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) ((array) $row)['task_id'] => (int) ((array) $row)['total']])
            ->all();

        $tasks = Task::query()
            ->withTrashed()
            ->where(fn (Builder $query) => $query
                ->whereKey(array_keys($minutesByTask))
                ->orWhere(fn (Builder $mine) => $mine
                    ->where('assignee_user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $when) => $when
                        ->whereBetween('completed_at', [
                            CarbonImmutable::parse($from, WeeklyCalendar::TIMEZONE)->startOfDay()->utc(),
                            CarbonImmutable::parse($to, WeeklyCalendar::TIMEZONE)->endOfDay()->utc(),
                        ])
                        ->orWhere(fn (Builder $due) => $due->whereNull('completed_at')->whereBetween('due_date', [$from, $to])))))
            ->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'client_id'])])
            ->orderBy('title')
            ->get(['id', 'project_id', 'title', 'description', 'completed_at', 'deleted_at']);

        $lines = [];

        foreach ($tasks as $task) {
            $key = $task->project->client_id === null ? 'general' : (string) $task->project->client_id;
            $minutes = $minutesByTask[$task->id] ?? 0;
            $line = __($task->completed_at !== null ? 'weeklies.autofill.done' : 'weeklies.autofill.pending', ['task' => $task->title]);

            if ($minutes > 0) {
                $line .= ' '.__('weeklies.autofill.time', ['time' => self::duration($minutes)]);
            }

            // Sus notas, en una línea, como « - Nota: …» de WeeklySync (F-048, 10.9b).
            $note = trim((string) preg_replace('/\s+/u', ' ', TaskNotes::toPlain($task->description)));

            if ($note !== '') {
                $line .= ' '.__('weeklies.autofill.note', ['note' => Str::limit($note, self::NOTE_LIMIT)]);
            }

            $lines[$key][] = $line;
        }

        return array_map(fn (array $rows): string => implode("\n", $rows), $lines);
    }

    /** «1 h 30 min», «45 min», «2 h». */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} min",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h {$rest} min",
        };
    }

    /**
     * De lunes a domingo de la semana (Y-m-d).
     *
     * @return array{0: string, 1: string}
     */
    private function range(WeeklyCycle $cycle): array
    {
        $start = CarbonImmutable::parse($cycle->start_date->toDateString());

        return [$start->toDateString(), $start->addDays(6)->toDateString()];
    }
}
