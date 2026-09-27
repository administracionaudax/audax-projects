<?php

namespace App\Domain\Workload;

use App\Enums\BillingType;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tareas «cajón» de los proyectos internos (D-033): abiertas y sin estimación, sirven para que
 * cualquiera impute reuniones, formación o gestión. No son trabajo que repartir ni planificar, así
 * que no van a las bandejas «Sin asignar» y «Sin planificar» de la vista Carga ni cuentan en «Mi
 * carga» (una tarea interna CON estimación sí es trabajo y sí va). Una consulta: son pocas.
 */
final class InternalBuckets
{
    /**
     * @return array<int, true> id de la tarea → true
     */
    public static function ids(?int $assigneeId = null): array
    {
        return array_fill_keys(
            Task::query()
                ->open()
                ->where(fn (Builder $query) => $query->whereNull('estimated_minutes')->orWhere('estimated_minutes', 0))
                ->whereHas('project', fn (Builder $query) => $query->where('billing_type', BillingType::Internal->value))
                ->when($assigneeId !== null, fn (Builder $query) => $query->where('assignee_user_id', $assigneeId))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            true,
        );
    }
}
