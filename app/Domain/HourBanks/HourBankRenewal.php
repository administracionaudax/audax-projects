<?php

namespace App\Domain\HourBanks;

use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renovación de una bolsa (SPEC §8.7 y §8.8):
 * - solo desde una bolsa agotada o «próxima a agotarse» (consumo ≥ primer umbral, D-035),
 * - crea una bolsa nueva con los mismos parámetros (editables) y renewed_from_id,
 * - la anterior pasa a «renovada» (ya no admite horas),
 * - opcionalmente mueve TODAS las tareas abiertas (también los hitos): cada tarea de primer nivel
 *   va con todas sus subtareas, y una subtarea abierta arrastra a su padre aunque esté terminado
 *   (las subtareas van siempre en la bolsa del padre, D-037),
 * - las horas imputadas NUNCA se mueven: sus entradas conservan la bolsa anterior, y su exceso
 *   solo se muestra como información.
 * Todo en una transacción, con la bolsa anterior bloqueada.
 */
final class HourBankRenewal
{
    /**
     * Campos que se copian de la bolsa anterior si no llegan otros.
     */
    public const array COPIED = [
        'name', 'department_id', 'total_minutes', 'hourly_rate', 'price_amount', 'start_date', 'end_date',
        'overage_policy', 'invoice_reference', 'notes',
    ];

    private ?int $threshold = null;

    public function __construct(private readonly HourBankLedger $ledger) {}

    /**
     * ¿Toca renovarla? Agotada, o activa con un consumo igual o superior al primer umbral de
     * alerta («próxima a agotarse», D-035).
     */
    public function isDue(HourBank $bank): bool
    {
        return match ($bank->status) {
            HourBankStatus::Exhausted => true,
            HourBankStatus::Active => $bank->consumed_minutes * 100 >= $this->firstThreshold() * $bank->total_minutes,
            default => false,
        };
    }

    /**
     * Primer umbral de alerta configurado (75 % por defecto).
     */
    public function firstThreshold(): int
    {
        return $this->threshold ??= $this->ledger->thresholds()[0] ?? 75;
    }

    /**
     * @param  array<string, mixed>  $attributes  parámetros de la bolsa nueva (validados)
     * @return array{bank: HourBank, moved_tasks: int} moved_tasks: tareas abiertas movidas
     *
     * @throws ValidationException
     */
    public function renew(HourBank $previous, array $attributes, bool $moveOpenTasks): array
    {
        return DB::transaction(function () use ($previous, $attributes, $moveOpenTasks): array {
            $locked = $this->ledger->lock($previous);

            if (! $locked->status->acceptsTime()) {
                throw ValidationException::withMessages([
                    'hour_bank' => __('hour_banks.errors.not_open', [
                        'bank' => $locked->name,
                        'status' => mb_strtolower($locked->status->label()),
                    ]),
                ]);
            }

            if (! $this->isDue($locked)) {
                throw ValidationException::withMessages([
                    'hour_bank' => __('hour_banks.errors.not_due', ['threshold' => $this->firstThreshold()]),
                ]);
            }

            $copied = [];
            foreach (self::COPIED as $field) {
                $copied[$field] = $locked->getAttribute($field);
            }

            $bank = HourBank::query()->create([
                ...$copied,
                ...array_intersect_key($attributes, array_flip(self::COPIED)),
                'project_id' => $locked->project_id,
                'status' => HourBankStatus::Active,
                'renewed_from_id' => $locked->id,
            ]);

            $locked->status = HourBankStatus::Renewed;
            $locked->save();

            $moved = $moveOpenTasks ? $this->moveOpenTasks($locked, $bank) : 0;

            $previous->setRawAttributes($locked->getAttributes(), true);

            return ['bank' => $bank, 'moved_tasks' => $moved];
        });
    }

    /**
     * Mueve todas las tareas abiertas de la bolsa (una a una, para que quede en la auditoría de
     * cada tarea): cada tarea de primer nivel con todas sus subtareas, y una subtarea abierta junto
     * a su padre (y los hermanos de este), aunque el padre esté terminado. Las tareas terminadas sin
     * nada abierto se quedan. Devuelve cuántas tareas abiertas se han movido (las mismas que cuenta
     * HourBankCommitment como open_tasks_count).
     */
    private function moveOpenTasks(HourBank $from, HourBank $to): int
    {
        $tasks = Task::query()
            ->where('hour_bank_id', $from->id)
            ->orderBy('id')
            ->get();

        $byId = $tasks->keyBy('id');
        /** @var array<int, list<Task>> $children */
        $children = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null) {
                $children[$task->parent_task_id][] = $task;
            }
        }

        /** @var array<int, Task> $move */
        $move = [];
        $open = 0;

        foreach ($tasks as $task) {
            if ($task->completed_at !== null) {
                continue;
            }

            $open++;
            // La tarea de primer nivel de la que cuelga (si su padre está en esta bolsa).
            $root = $task->parent_task_id !== null && $byId->has($task->parent_task_id)
                ? $byId->get($task->parent_task_id)
                : $task;

            $move[$root->id] = $root;
            foreach ($children[$root->id] ?? [] as $subtask) {
                $move[$subtask->id] = $subtask;
            }
        }

        foreach ($move as $task) {
            $task->hour_bank_id = $to->id;
            $task->save();
        }

        return $open;
    }
}
