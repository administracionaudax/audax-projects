<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\User;

/**
 * Tu plan del día (D-256): cada línea con su día, texto, cliente, proyecto, tarea, horas previstas,
 * estado (y motivo de «no hecha»), si se pasó de otro día y cuándo la escribiste. También la nota de
 * cada día. Las horas imputadas van en su propia sección.
 */
final class DayPlansSection extends Section
{
    public function key(): string
    {
        return 'plan-del-dia';
    }

    protected function textKey(): string
    {
        return 'day_plans';
    }

    protected function columnKeys(): array
    {
        return ['id', 'date', 'position', 'text', 'client', 'project', 'task', 'planned_minutes', 'status', 'not_done_reason', 'carry_count', 'note', 'deleted', 'created_at', 'status_changed_at'];
    }

    public function rows(User $user): iterable
    {
        $notes = DayPlan::query()->where('user_id', $user->id)->whereNotNull('note')->pluck('note', 'id');

        $items = DayPlanItem::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->with(['client:id,name', 'project:id,code,name', 'task:id,title'])
            ->orderBy('date')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            yield [
                'id' => $item->id,
                'date' => self::date($item->date),
                'position' => $item->position,
                'text' => $item->text,
                'client' => $item->client?->name,
                'project' => $item->project === null ? null : "{$item->project->code} · {$item->project->name}",
                'task' => $item->task?->title,
                'planned_minutes' => $item->planned_minutes,
                'status' => $item->status->label(),
                'not_done_reason' => $item->not_done_reason,
                'carry_count' => $item->carry_count,
                'note' => $notes[$item->day_plan_id] ?? null,
                'deleted' => $item->trashed(),
                'created_at' => self::instant($item->created_at),
                'status_changed_at' => self::instant($item->status_changed_at),
            ];
        }
    }
}
