<?php

namespace App\Domain\Planning;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Próximos hitos (SPEC §5.1 y §6, D-062). Un hito es una tarea con is_milestone; «sin completar»
 * es sin completed_at; las fechas de tarea son fechas locales (hoy en Madrid). Una consulta en el
 * resumen del proyecto y dos acotadas en Inicio. Contrato: resources/js/types/planning.ts
 * (ProjectMilestones y HomeMilestone).
 */
final class UpcomingMilestones
{
    /** Resumen del proyecto: los siguientes sin completar. */
    public const int PROJECT_UPCOMING = 5;

    /** Resumen del proyecto: vencidos que se enseñan (los más atrasados primero). */
    public const int PROJECT_OVERDUE = 10;

    /** Inicio: como mucho estos hitos. */
    public const int HOME_LIMIT = 8;

    /** Inicio: vencidos y los de los próximos días. */
    public const int HOME_DAYS = 30;

    /** Inicio: huecos para los vencidos cuando los próximos llenan la tarjeta. */
    public const int HOME_OVERDUE = 3;

    private const array HOME_COLUMNS = [
        'tasks.id', 'tasks.project_id', 'tasks.title', 'tasks.due_date',
        'projects.code as project_code', 'projects.name as project_name', 'projects.color as project_color',
    ];

    /**
     * Hitos sin completar del proyecto: los vencidos (destacados) y los 5 siguientes por entrega,
     * más cuántos no tienen fecha. Los hitos de un proyecto son pocos: una consulta con todos
     * los abiertos y el reparto en memoria.
     *
     * @return array{overdue: list<array<string, mixed>>, overdue_total: int, upcoming: list<array<string, mixed>>, undated_count: int, today: string}
     */
    public function forProject(Project $project, ?CarbonImmutable $today = null): array
    {
        $todayString = ($today ?? LocalTime::today())->toDateString();

        $milestones = Task::query()
            ->where('project_id', $project->id)
            ->where('is_milestone', true)
            ->open()
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'title', 'due_date']);

        $overdue = [];
        $upcoming = [];
        $undated = 0;

        foreach ($milestones as $milestone) {
            $due = $milestone->due_date?->toDateString();

            if ($due === null) {
                $undated++;
            } elseif ($due < $todayString) {
                $overdue[] = $this->item($milestone, $due, $todayString);
            } elseif (count($upcoming) < self::PROJECT_UPCOMING) {
                $upcoming[] = $this->item($milestone, $due, $todayString);
            }
        }

        return [
            'overdue' => array_slice($overdue, 0, self::PROJECT_OVERDUE),
            'overdue_total' => count($overdue),
            'upcoming' => $upcoming,
            'undated_count' => $undated,
            'today' => $todayString,
        ];
    }

    /**
     * Inicio, «Mis próximos hitos»: hitos sin completar de los proyectos planificados o activos de
     * los que soy miembro (D-021: solo lo mío; los en pausa, completados y archivados, fuera), con
     * los vencidos arriba y después los de los próximos 30 días, por entrega; como mucho 8.
     *
     * Los vencidos no pueden desplazar a los próximos (la tarjeta es de «próximos»): se piden por
     * separado y, si hay próximos que llenen la tarjeta, los vencidos ocupan como mucho 3 huecos
     * (los más recientes). Si hay menos próximos, los vencidos llenan el resto. Una consulta: las
     * dos listas acotadas (LIMIT 8 cada una) con UNION ALL, y los datos del proyecto por join.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, ?CarbonImmutable $today = null): array
    {
        $day = CarbonImmutable::parse(($today ?? LocalTime::today())->toDateString());
        $todayString = $day->toDateString();

        // Los vencidos más recientes (los muy antiguos no llenan la tarjeta) y los próximos. Cada
        // parte lleva su orden y su límite (SQLite y PostgreSQL envuelven cada SELECT de la unión).
        $rows = $this->homeQuery($user)
            ->where('tasks.due_date', '<', $todayString)
            ->orderByDesc('tasks.due_date')
            ->orderByDesc('tasks.id')
            ->limit(self::HOME_LIMIT)
            ->unionAll($this->homeQuery($user)
                ->where('tasks.due_date', '>=', $todayString)
                ->where('tasks.due_date', '<=', $day->addDays(self::HOME_DAYS)->toDateString())
                ->orderBy('tasks.due_date')
                ->orderBy('tasks.id')
                ->limit(self::HOME_LIMIT))
            ->get();

        // La unión no garantiza el orden: cada lista se ordena aquí.
        $due = fn (Task $milestone): string => (string) $milestone->due_date?->toDateString();
        $overdue = $rows->filter(fn (Task $milestone): bool => $due($milestone) < $todayString)
            ->sort(fn (Task $a, Task $b): int => [$due($b), $b->id] <=> [$due($a), $a->id])
            ->values();
        $upcoming = $rows->filter(fn (Task $milestone): bool => $due($milestone) >= $todayString)
            ->sort(fn (Task $a, Task $b): int => [$due($a), $a->id] <=> [$due($b), $b->id])
            ->values();

        $overdueShown = min($overdue->count(), max(self::HOME_OVERDUE, self::HOME_LIMIT - $upcoming->count()));
        $upcomingShown = min($upcoming->count(), self::HOME_LIMIT - $overdueShown);

        // Todo por entrega: los vencidos elegidos (del más atrasado al más reciente) y los próximos.
        $shown = $overdue->take($overdueShown)->reverse()->concat($upcoming->take($upcomingShown));

        return array_values($shown->map(fn (Task $milestone): array => [
            ...$this->item($milestone, (string) $milestone->due_date?->toDateString(), $todayString),
            'project' => [
                'id' => $milestone->project_id,
                'code' => (string) $milestone->getAttribute('project_code'),
                'name' => (string) $milestone->getAttribute('project_name'),
                'color' => (string) $milestone->getAttribute('project_color'),
            ],
        ])->all());
    }

    /**
     * Hitos con fecha y sin completar de los proyectos planificados o activos de los que soy miembro.
     *
     * @return Builder<Task>
     */
    private function homeQuery(User $user): Builder
    {
        return Task::query()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.status', [ProjectStatus::Planned->value, ProjectStatus::Active->value])
            ->whereExists(fn (QueryBuilder $member) => $member->selectRaw('1')
                ->from('project_members')
                ->whereColumn('project_members.project_id', 'tasks.project_id')
                ->where('project_members.user_id', $user->id))
            ->where('tasks.is_milestone', true)
            ->whereNull('tasks.completed_at')
            ->whereNotNull('tasks.due_date')
            ->select(self::HOME_COLUMNS);
    }

    /**
     * @return array{id: int, project_id: int, title: string, due_date: string, is_overdue: bool, days: int}
     */
    private function item(Task $milestone, string $due, string $today): array
    {
        return [
            'id' => $milestone->id,
            'project_id' => $milestone->project_id,
            'title' => $milestone->title,
            'due_date' => $due,
            'is_overdue' => $due < $today,
            // Días hasta la entrega (negativo si está vencido): fechas locales, sin zona.
            'days' => (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($due), false),
        ];
    }
}
