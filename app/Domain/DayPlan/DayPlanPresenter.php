<?php

namespace App\Domain\DayPlan;

use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Collection;

/**
 * Las líneas del plan del día como las recibe la interfaz (resources/js/types/day-plan.ts,
 * DayPlanLine). Sin las cifras (horas previstas e imputadas, temporizador) ni los comentarios para
 * quien no puede verlos (D-251): esos campos llegan a null, no solo ocultos en la pantalla.
 *
 * @phpstan-type Line array{
 *     id: int, user_id: int, date: string, position: int, text: string, status: string,
 *     not_done_reason: string|null, carry_count: int, carried_from_date: string|null, origin: string,
 *     client: array{id: int, name: string}|null,
 *     project: array{id: int, code: string, name: string, color: string}|null,
 *     task: array{id: int, title: string, project_id: int, is_completed: bool}|null,
 *     created_at: string|null, added_late: bool,
 *     planned_minutes: int|null, logged_minutes: int|null, running: bool,
 *     comments: list<array{id: int, body: string, user: array{id: int, name: string}, created_at: string|null, can_delete: bool}>|null
 * }
 */
final class DayPlanPresenter
{
    /** Relaciones que necesita line() (una consulta por relación, sin N+1). */
    public const array WITH = [
        'client:id,name',
        'project:id,code,name,color,client_id',
        'task:id,title,project_id,completed_at',
        'carriedFrom:id,date',
    ];

    /**
     * Minutos imputados por línea (todas las entradas enlazadas, sea cual sea su estado, §6.1.2).
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    public static function loggedByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return TimeEntry::query()
            ->whereIn('day_plan_item_id', $itemIds)
            ->groupBy('day_plan_item_id')
            ->selectRaw('day_plan_item_id, SUM(minutes) AS total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) ((array) $row)['day_plan_item_id'] => (int) ((array) $row)['total']])
            ->all();
    }

    /**
     * Comentarios por línea, con su autor.
     *
     * @param  list<int>  $itemIds
     * @return array<int, Collection<int, DayPlanComment>>
     */
    public static function commentsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DayPlanComment::query()
            ->whereIn('day_plan_item_id', $itemIds)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('day_plan_item_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $logged  minutos por línea (solo si se ven las cifras)
     * @param  array<int, Collection<int, DayPlanComment>>|null  $comments  null = no se ven
     * @return Line
     */
    public static function line(DayPlanItem $item, bool $figures, array $logged = [], ?int $runningItemId = null, ?array $comments = null, ?User $viewer = null): array
    {
        $deadline = DayPlanCalendar::deadlineOn($item->date);

        return [
            'id' => $item->id,
            'user_id' => $item->user_id,
            'date' => $item->date->toDateString(),
            'position' => $item->position,
            'text' => $item->text,
            'status' => $item->status->value,
            'not_done_reason' => $item->not_done_reason,
            'carry_count' => $item->carry_count,
            'carried_from_date' => $item->carriedFrom?->date->toDateString(),
            'origin' => $item->origin->value,
            'client' => $item->client === null ? null : ['id' => $item->client->id, 'name' => $item->client->name],
            'project' => $item->project === null ? null : [
                'id' => $item->project->id,
                'code' => $item->project->code,
                'name' => $item->project->name,
                'color' => $item->project->color,
            ],
            'task' => $item->task === null ? null : [
                'id' => $item->task->id,
                'title' => $item->task->title,
                'project_id' => $item->task->project_id,
                'is_completed' => $item->task->completed_at !== null,
            ],
            'created_at' => $item->created_at?->toIso8601ZuluString(),
            // «Añadida a las 12:40» (§4.4): creada el mismo día después de la hora límite.
            'added_late' => $item->created_at !== null
                && LocalTime::dateOf($item->created_at) === $item->date->toDateString()
                && $item->created_at > $deadline,
            'planned_minutes' => $figures ? $item->planned_minutes : null,
            'logged_minutes' => $figures ? ($logged[$item->id] ?? 0) : null,
            'running' => $figures && $runningItemId === $item->id,
            'comments' => $comments === null ? null : array_values(($comments[$item->id] ?? collect())->map(fn (DayPlanComment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => ['id' => $comment->user->id, 'name' => $comment->user->name],
                'created_at' => $comment->created_at?->toIso8601ZuluString(),
                'can_delete' => $viewer !== null && $viewer->id === $comment->user_id,
            ])->all()),
        ];
    }
}
