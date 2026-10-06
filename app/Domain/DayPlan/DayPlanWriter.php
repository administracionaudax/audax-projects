<?php

namespace App\Domain\DayPlan;

use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Models\Client;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICO punto de escritura del plan del día (docs/PLAN-CARGAS.md §4.4 y §7.5, D-250 a D-253), como
 * TimeEntryWriter con las horas. Cada persona escribe solo el suyo (DayPlanItemPolicy lo comprueba
 * antes; aquí se repite para cualquier otra vía):
 *
 * - **Añadir** una línea (texto obligatorio; cliente, proyecto, tarea y horas previstas opcionales):
 *   hoy o cualquier día hasta el domingo de la semana que viene. La primera línea del día fija
 *   `published_at`. Elegir una tarea fija su proyecto y su cliente; un proyecto, su cliente.
 * - **Editar, borrar y ordenar**: solo hoy y los días que vienen (el plan no se reescribe a
 *   posteriori). Borrar la copia de una línea pasada devuelve la original a «pendiente».
 * - **Cerrar** (hecha, no hecha con motivo, pendiente otra vez, pasar a otro día): también en los
 *   últimos días con jornada que permite el ajuste (DayPlanCalendar::closable).
 * - **Pasar a otro día** crea una línea nueva en el día de destino (`carried_from_id`, la marca
 *   «↻ ×N» con `carry_count`) y deja la original como «pasada». Nunca es automático (P3).
 * El cliente, el proyecto y la tarea deben ser visibles para la persona (canSeeProject).
 */
final class DayPlanWriter
{
    /** Líneas como máximo en un día (evita listas sin fin por error). */
    public const int MAX_ITEMS_PER_DAY = 60;

    public function __construct(private readonly DayPlanCalendar $calendar) {}

    /**
     * @param  array{text: string, client_id?: int|null, project_id?: int|null, task_id?: int|null, planned_minutes?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function add(User $user, string $date, array $data, ?int $afterId = null, DayPlanItemOrigin $origin = DayPlanItemOrigin::Manual): DayPlanItem
    {
        $this->assertWritable($date);
        $text = $this->text($data['text']);
        $target = $this->target($user, $data['client_id'] ?? null, $data['project_id'] ?? null, $data['task_id'] ?? null);

        return DB::transaction(function () use ($user, $date, $data, $afterId, $origin, $text, $target): DayPlanItem {
            $plan = $this->plan($user, $date);
            $count = DayPlanItem::query()->where('day_plan_id', $plan->id)->count();

            if ($count >= self::MAX_ITEMS_PER_DAY) {
                throw ValidationException::withMessages(['text' => __('day_plan.errors.too_many', ['max' => self::MAX_ITEMS_PER_DAY])]);
            }

            $item = DayPlanItem::query()->create([
                'day_plan_id' => $plan->id,
                'user_id' => $user->id,
                'date' => $date,
                'position' => $this->positionAfter($plan, $afterId),
                'text' => $text,
                ...$target,
                'planned_minutes' => $this->minutes($data['planned_minutes'] ?? null),
                'status' => DayPlanItemStatus::Pending,
                'origin' => $origin,
                'created_by' => $user->id,
            ]);

            $this->publish($plan);

            return $item;
        });
    }

    /**
     * Cambios parciales: solo las claves presentes en $data. Cambiar el proyecto quita la tarea si
     * es de otro proyecto; cambiar el cliente quita el proyecto y la tarea si son de otro cliente.
     *
     * @param  array{text?: string, client_id?: int|null, project_id?: int|null, task_id?: int|null, planned_minutes?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, DayPlanItem $item, array $data): DayPlanItem
    {
        $this->assertOwner($actor, $item);
        $this->assertWritable($item->date->toDateString());
        $this->assertNotCarried($item);

        $fill = [];

        if (array_key_exists('text', $data)) {
            $fill['text'] = $this->text((string) $data['text']);
        }

        if (array_key_exists('planned_minutes', $data)) {
            $fill['planned_minutes'] = $this->minutes($data['planned_minutes']);
        }

        if (array_key_exists('task_id', $data) || array_key_exists('project_id', $data) || array_key_exists('client_id', $data)) {
            $taskId = array_key_exists('task_id', $data) ? $data['task_id'] : $item->task_id;
            $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : $item->project_id;
            $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : $item->client_id;

            // Lo que se cambia manda sobre lo que se conserva: un proyecto nuevo deja la tarea de otro
            // proyecto fuera y un cliente nuevo, el proyecto de otro cliente.
            if (! array_key_exists('task_id', $data) && $taskId !== null && array_key_exists('project_id', $data)) {
                $taskProject = Task::query()->withTrashed()->whereKey($taskId)->value('project_id');
                $taskId = $projectId !== null && (int) $taskProject === (int) $projectId ? $taskId : null;
            }

            if (! array_key_exists('project_id', $data) && ! array_key_exists('task_id', $data) && $projectId !== null && array_key_exists('client_id', $data)) {
                $projectClient = Project::query()->withTrashed()->whereKey($projectId)->value('client_id');

                if ($clientId === null || (int) $projectClient !== (int) $clientId) {
                    $projectId = null;
                    $taskId = null;
                }
            }

            $fill = [...$fill, ...$this->target($actor, $clientId, $projectId, $taskId)];
        }

        $item->fill($fill)->save();

        return $item;
    }

    /**
     * Hecha, no hecha (con motivo opcional) o pendiente otra vez.
     *
     * @throws ValidationException
     */
    public function setStatus(User $actor, DayPlanItem $item, DayPlanItemStatus $status, ?string $reason = null): DayPlanItem
    {
        $this->assertOwner($actor, $item);
        $this->assertNotCarried($item);
        $this->assertClosable($actor, $item);

        if ($status === DayPlanItemStatus::Carried) {
            throw ValidationException::withMessages(['status' => __('day_plan.errors.status')]);
        }

        $reason = $status === DayPlanItemStatus::NotDone ? $this->reason($reason) : null;

        if ($item->status === $status && $item->not_done_reason === $reason) {
            return $item;
        }

        $item->fill([
            'status' => $status,
            'status_changed_at' => now(),
            'not_done_reason' => $reason,
        ])->save();

        return $item;
    }

    /**
     * Pasa una línea pendiente (o no hecha) a otro día: copia en el destino y la original, «pasada».
     *
     * @throws ValidationException
     */
    public function carry(User $actor, DayPlanItem $item, string $toDate): DayPlanItem
    {
        $this->assertOwner($actor, $item);
        $this->assertNotCarried($item);
        $this->assertClosable($actor, $item);
        $this->assertWritable($toDate, 'date');

        if ($item->status === DayPlanItemStatus::Done) {
            throw ValidationException::withMessages(['status' => __('day_plan.errors.carry_done')]);
        }

        if ($toDate === $item->date->toDateString()) {
            throw ValidationException::withMessages(['date' => __('day_plan.errors.carry_same_day')]);
        }

        return DB::transaction(function () use ($actor, $item, $toDate): DayPlanItem {
            /** @var DayPlanItem $current */
            $current = DayPlanItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($current->status === DayPlanItemStatus::Carried) {
                throw ValidationException::withMessages(['status' => __('day_plan.errors.already_carried')]);
            }

            $plan = $this->plan($actor, $toDate);

            $copy = DayPlanItem::query()->create([
                'day_plan_id' => $plan->id,
                'user_id' => $current->user_id,
                'date' => $toDate,
                'position' => $this->positionAfter($plan, null),
                'text' => $current->text,
                'client_id' => $current->client_id,
                'project_id' => $current->project_id,
                'task_id' => $current->task_id,
                'planned_minutes' => $current->planned_minutes,
                'status' => DayPlanItemStatus::Pending,
                'carried_from_id' => $current->id,
                'carry_count' => $current->carry_count + 1,
                'origin' => DayPlanItemOrigin::Carried,
                'created_by' => $actor->id,
            ]);

            $current->fill([
                'status' => DayPlanItemStatus::Carried,
                'status_changed_at' => now(),
                'not_done_reason' => null,
            ])->save();

            $this->publish($plan);
            $item->setRawAttributes($current->getAttributes(), true);

            return $copy;
        });
    }

    /**
     * «Pasar a hoy» varias líneas a la vez (todas las pendientes o las elegidas), en su orden.
     *
     * @param  list<int>  $itemIds
     * @return list<DayPlanItem> las copias
     *
     * @throws ValidationException
     */
    public function carryMany(User $actor, array $itemIds, string $toDate): array
    {
        $items = $this->ownItems($actor, $itemIds);

        return DB::transaction(function () use ($actor, $items, $toDate): array {
            $copies = [];

            foreach ($items as $item) {
                $copies[] = $this->carry($actor, $item, $toDate);
            }

            return $copies;
        });
    }

    /**
     * «Marcar como no hechas» varias líneas a la vez.
     *
     * @param  list<int>  $itemIds
     *
     * @throws ValidationException
     */
    public function markManyNotDone(User $actor, array $itemIds): int
    {
        $items = $this->ownItems($actor, $itemIds);

        return DB::transaction(function () use ($actor, $items): int {
            foreach ($items as $item) {
                $this->setStatus($actor, $item, DayPlanItemStatus::NotDone);
            }

            return count($items);
        });
    }

    /**
     * Borra (sin perder el histórico: borrado lógico). Si era la copia de una línea pasada, la
     * original vuelve a «pendiente» («deshacer pasar»). Sus horas se conservan (sin línea).
     *
     * @throws ValidationException
     */
    public function delete(User $actor, DayPlanItem $item): void
    {
        $this->assertOwner($actor, $item);
        $this->assertWritable($item->date->toDateString());

        DB::transaction(function () use ($item): void {
            $item->delete();

            if ($item->carried_from_id !== null) {
                DayPlanItem::query()
                    ->whereKey($item->carried_from_id)
                    ->where('status', DayPlanItemStatus::Carried->value)
                    ->first()
                    ?->fill(['status' => DayPlanItemStatus::Pending, 'status_changed_at' => now()])
                    ->save();
            }
        });
    }

    /**
     * Nuevo orden de las líneas de un día (los ids que no son de ese día se ignoran; los que faltan,
     * al final en su orden).
     *
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    public function reorder(User $user, string $date, array $ids): void
    {
        $this->assertWritable($date);

        DB::transaction(function () use ($user, $date, $ids): void {
            $items = DayPlanItem::query()
                ->where('user_id', $user->id)
                ->where('date', $date)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'position']);

            $known = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ordered = array_values(array_unique(array_intersect($ids, $known)));
            $ordered = [...$ordered, ...array_values(array_diff($known, $ordered))];

            foreach ($ordered as $position => $id) {
                $item = $items->firstWhere('id', $id);

                if ($item !== null && $item->position !== $position) {
                    DayPlanItem::query()->whereKey($id)->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * La nota del día («Hoy tengo médico a las 12»). Vacía, se quita.
     *
     * @throws ValidationException
     */
    public function setNote(User $user, string $date, ?string $note): DayPlan
    {
        $this->assertWritable($date);
        $note = trim((string) $note);

        return DB::transaction(function () use ($user, $date, $note): DayPlan {
            $plan = $this->plan($user, $date);
            $plan->note = $note === '' ? null : mb_substr($note, 0, 500);
            $plan->save();

            return $plan;
        });
    }

    /**
     * La tarea que resuelve la línea (temporizador, horas imputadas a mano): queda guardada con su
     * proyecto y su cliente para la próxima vez. Una línea ya «pasada» no cambia.
     */
    public function adoptTask(DayPlanItem $item, Task $task): DayPlanItem
    {
        if ($item->task_id === $task->id || $item->status === DayPlanItemStatus::Carried) {
            return $item;
        }

        $task->loadMissing(['project' => fn ($query) => $query->withTrashed()]);

        $item->fill([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'client_id' => $task->project->client_id,
        ])->save();

        return $item;
    }

    /**
     * La línea de $user que se puede usar para imputar o cronometrar: suya, sin borrar, no pasada y
     * en un día que aún se puede cerrar.
     *
     * @throws ValidationException
     */
    public function assertLoggable(User $user, DayPlanItem $item, string $field = 'day_plan_item_id'): void
    {
        if ($item->user_id !== $user->id || $item->trashed()) {
            throw ValidationException::withMessages([$field => __('day_plan.errors.not_yours')]);
        }

        if ($item->status === DayPlanItemStatus::Carried) {
            throw ValidationException::withMessages([$field => __('day_plan.errors.already_carried')]);
        }

        if (! $this->calendar->closable($user, $item->date)) {
            throw ValidationException::withMessages([$field => __('day_plan.errors.read_only')]);
        }
    }

    /**
     * Cabecera del día (se crea si falta), bloqueada para la transacción en curso.
     */
    public function plan(User $user, string $date): DayPlan
    {
        DayPlan::query()->insertOrIgnore([
            'user_id' => $user->id,
            'date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DayPlan */
        return DayPlan::query()->where('user_id', $user->id)->where('date', $date)->lockForUpdate()->firstOrFail();
    }

    private function publish(DayPlan $plan): void
    {
        if ($plan->published_at === null) {
            $plan->published_at = CarbonImmutable::now();
            $plan->save();
        }
    }

    /**
     * Posición nueva: al final o justo después de $afterId (las siguientes bajan una).
     */
    private function positionAfter(DayPlan $plan, ?int $afterId): int
    {
        if ($afterId !== null) {
            $after = DayPlanItem::query()->where('day_plan_id', $plan->id)->whereKey($afterId)->value('position');

            if ($after !== null) {
                DayPlanItem::query()
                    ->withTrashed()
                    ->where('day_plan_id', $plan->id)
                    ->where('position', '>', (int) $after)
                    ->increment('position');

                return (int) $after + 1;
            }
        }

        $max = DayPlanItem::query()->withTrashed()->where('day_plan_id', $plan->id)->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     * Cliente, proyecto y tarea coherentes y visibles para $user: la tarea fija el proyecto y el
     * cliente; el proyecto, el cliente.
     *
     * @return array{client_id: int|null, project_id: int|null, task_id: int|null}
     *
     * @throws ValidationException
     */
    private function target(User $user, mixed $clientId, mixed $projectId, mixed $taskId): array
    {
        $clientId = $this->id($clientId);
        $projectId = $this->id($projectId);
        $taskId = $this->id($taskId);

        if ($taskId !== null) {
            $task = Task::query()->with(['project' => fn ($query) => $query->withTrashed()])->find($taskId);

            if ($task === null || ! $user->canSeeProject($task->project_id)) {
                throw ValidationException::withMessages(['task_id' => __('day_plan.errors.task')]);
            }

            return ['client_id' => $task->project->client_id, 'project_id' => $task->project_id, 'task_id' => $task->id];
        }

        if ($projectId !== null) {
            $project = Project::query()->find($projectId);

            if ($project === null || ! $user->canSeeProject($project)) {
                throw ValidationException::withMessages(['project_id' => __('day_plan.errors.project')]);
            }

            return ['client_id' => $project->client_id, 'project_id' => $project->id, 'task_id' => null];
        }

        if ($clientId !== null && ! Client::query()->whereKey($clientId)->exists()) {
            throw ValidationException::withMessages(['client_id' => __('day_plan.errors.client')]);
        }

        return ['client_id' => $clientId, 'project_id' => null, 'task_id' => null];
    }

    /**
     * @param  list<int>  $itemIds
     * @return list<DayPlanItem> en el orden de su día y su posición
     *
     * @throws ValidationException
     */
    private function ownItems(User $actor, array $itemIds): array
    {
        $items = DayPlanItem::query()
            ->whereKey($itemIds)
            ->orderBy('date')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($items->count() !== count(array_unique($itemIds)) || $items->contains(fn (DayPlanItem $item): bool => $item->user_id !== $actor->id)) {
            throw ValidationException::withMessages(['items' => __('day_plan.errors.not_yours')]);
        }

        return array_values($items->all());
    }

    private function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @throws ValidationException
     */
    private function text(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('day_plan.errors.text_required')]);
        }

        if (mb_strlen($text) > DayPlanItem::TEXT_MAX) {
            throw ValidationException::withMessages(['text' => __('day_plan.errors.text_max', ['max' => DayPlanItem::TEXT_MAX])]);
        }

        return $text;
    }

    private function reason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, DayPlanItem::TEXT_MAX);
    }

    /**
     * @throws ValidationException
     */
    private function minutes(mixed $minutes): ?int
    {
        if ($minutes === null || $minutes === '') {
            return null;
        }

        $minutes = (int) $minutes;

        if ($minutes < 1 || $minutes > 24 * 60) {
            throw ValidationException::withMessages(['planned_minutes' => __('day_plan.errors.minutes')]);
        }

        return $minutes;
    }

    /**
     * @throws ValidationException
     */
    private function assertOwner(User $actor, DayPlanItem $item): void
    {
        if ($item->user_id !== $actor->id) {
            throw ValidationException::withMessages(['item' => __('day_plan.errors.not_yours')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertWritable(string $date, string $field = 'date'): void
    {
        if (! DayPlanCalendar::writable($date)) {
            throw ValidationException::withMessages([$field => __('day_plan.errors.not_writable', [
                'until' => DayPlanCalendar::horizonEnd()->format('d/m/Y'),
            ])]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertClosable(User $actor, DayPlanItem $item): void
    {
        if (! $this->calendar->closable($actor, $item->date)) {
            throw ValidationException::withMessages(['status' => __('day_plan.errors.read_only')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotCarried(DayPlanItem $item): void
    {
        if ($item->status === DayPlanItemStatus::Carried) {
            throw ValidationException::withMessages(['status' => __('day_plan.errors.already_carried')]);
        }
    }
}
