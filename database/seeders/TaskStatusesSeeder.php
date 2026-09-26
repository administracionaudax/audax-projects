<?php

namespace Database\Seeders;

use App\Models\TaskStatus;
use Illuminate\Database\Seeder;

/**
 * Estados de tarea por defecto (SPEC §4.3, D-037): Por hacer, En curso, En revisión, Bloqueada y
 * Hecha. Idempotente: si ya hay estados (editados por el admin), no toca nada.
 */
class TaskStatusesSeeder extends Seeder
{
    public function run(): void
    {
        TaskStatus::ensureDefaults();
    }
}
