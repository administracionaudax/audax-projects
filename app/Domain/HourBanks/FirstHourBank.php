<?php

namespace App\Domain\HourBanks;

use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Primera bolsa de un proyecto que pasa a «bolsa de horas» teniendo ya tareas (SPEC §8.2: cada
 * tarea pertenece a una bolsa). Crea la bolsa y le asigna todas las tareas del proyecto que no
 * tienen bolsa (con sus subtareas, que van en la misma, D-037), una a una para que quede en la
 * auditoría de cada tarea. Las horas ya imputadas NUNCA se mueven: sus entradas siguen sin bolsa
 * y no cuentan en su consumo. Todo en una transacción.
 */
final class FirstHourBank
{
    /**
     * @param  array<string, mixed>  $attributes  datos de la bolsa (validados)
     * @return array{bank: HourBank, moved_tasks: int}
     */
    public function create(Project $project, array $attributes): array
    {
        return DB::transaction(function () use ($project, $attributes): array {
            $bank = HourBank::query()->create([
                ...array_intersect_key($attributes, array_flip(HourBankRenewal::COPIED)),
                'project_id' => $project->id,
                'status' => HourBankStatus::Active,
            ]);

            $tasks = Task::query()
                ->where('project_id', $project->id)
                ->whereNull('hour_bank_id')
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $task->hour_bank_id = $bank->id;
                $task->save();
            }

            return ['bank' => $bank, 'moved_tasks' => $tasks->count()];
        });
    }
}
