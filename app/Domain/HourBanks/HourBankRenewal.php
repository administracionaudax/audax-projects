<?php

namespace App\Domain\HourBanks;

use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renovación de una bolsa (SPEC §8.7 y §8.8):
 * - crea una bolsa nueva con los mismos parámetros (editables) y renewed_from_id,
 * - la anterior pasa a «renovada» (ya no admite horas),
 * - opcionalmente mueve las tareas ABIERTAS de primer nivel, con todas sus subtareas (van siempre
 *   en la bolsa del padre, D-037),
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

    public function __construct(private readonly HourBankLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $attributes  parámetros de la bolsa nueva (validados)
     * @return array{bank: HourBank, moved_tasks: int}
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
     * Mueve las tareas abiertas de primer nivel y sus subtareas (una a una, para que quede en la
     * auditoría de cada tarea). Devuelve cuántas tareas de primer nivel se han movido.
     */
    private function moveOpenTasks(HourBank $from, HourBank $to): int
    {
        $roots = Task::query()
            ->where('hour_bank_id', $from->id)
            ->whereNull('parent_task_id')
            ->whereNull('completed_at')
            ->get();

        if ($roots->isEmpty()) {
            return 0;
        }

        $subtasks = Task::query()
            ->where('hour_bank_id', $from->id)
            ->whereIn('parent_task_id', $roots->modelKeys())
            ->get();

        foreach ($roots->concat($subtasks) as $task) {
            $task->hour_bank_id = $to->id;
            $task->save();
        }

        return $roots->count();
    }
}
