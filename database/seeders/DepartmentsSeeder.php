<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Departamentos por defecto (Diseño, Desarrollo, Marketing). Idempotente: solo siembra si no hay
 * ninguno (contando los borrados), como TaskStatus::ensureDefaults. Así, volver a ejecutar
 * app:install (por ejemplo, con --reset-link) nunca recrea uno que el admin haya renombrado o
 * eliminado a propósito.
 */
class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::withTrashed()->exists()) {
            return;
        }

        foreach (Department::DEFAULTS as $department) {
            Department::query()->create([
                'name' => $department['name'],
                'color' => $department['color'],
            ]);
        }
    }
}
