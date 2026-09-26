<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Departamentos por defecto (Diseño, Desarrollo, Marketing). Idempotente: busca por nombre,
 * incluidos los borrados, para no recrear uno que el admin haya eliminado a propósito.
 */
class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Department::DEFAULTS as $department) {
            Department::withTrashed()->firstOrCreate(
                ['name' => $department['name']],
                ['color' => $department['color']],
            );
        }
    }
}
