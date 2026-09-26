<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\TaskType;
use Illuminate\Database\Seeder;

/**
 * Tipos de tarea por defecto (SPEC §4.3, TaskType::DEFAULTS), enlazados por nombre con los
 * departamentos por defecto. Idempotente: busca por nombre, incluidos los borrados, para no
 * recrear un tipo que el admin haya eliminado ni pisar los que haya editado.
 */
class TaskTypesSeeder extends Seeder
{
    public function run(): void
    {
        $departments = Department::query()->pluck('id', 'name');

        foreach (TaskType::DEFAULTS as $position => $type) {
            TaskType::withTrashed()->firstOrCreate(
                ['name' => $type['name']],
                [
                    'color' => $type['color'],
                    'icon' => $type['icon'],
                    'department_id' => $type['department'] !== null ? ($departments[$type['department']] ?? null) : null,
                    'is_billable_default' => $type['is_billable_default'],
                    'is_active' => true,
                    'position' => $position,
                ],
            );
        }
    }
}
