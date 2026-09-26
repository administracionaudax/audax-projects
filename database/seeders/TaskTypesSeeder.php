<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\TaskType;
use Illuminate\Database\Seeder;

/**
 * Tipos de tarea por defecto (SPEC §4.3, TaskType::DEFAULTS), enlazados por nombre con los
 * departamentos por defecto. Idempotente: solo siembra si el catálogo está vacío (contando los
 * borrados), como TaskStatus::ensureDefaults. Así, volver a ejecutar app:install nunca recrea un
 * tipo que el admin haya renombrado o eliminado, ni pisa los que haya editado.
 */
class TaskTypesSeeder extends Seeder
{
    public function run(): void
    {
        if (TaskType::withTrashed()->exists()) {
            return;
        }

        $departments = Department::query()->pluck('id', 'name');

        foreach (TaskType::DEFAULTS as $position => $type) {
            TaskType::query()->create([
                'name' => $type['name'],
                'color' => $type['color'],
                'icon' => $type['icon'],
                'department_id' => $type['department'] !== null ? ($departments[$type['department']] ?? null) : null,
                'is_billable_default' => $type['is_billable_default'],
                'is_active' => true,
                'position' => $position,
            ]);
        }
    }
}
