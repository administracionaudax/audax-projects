<?php

namespace Database\Seeders;

use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Proyecto interno por defecto (SPEC §7, D-033): «Interno – Agencia», sin cliente, no facturable,
 * con las tareas Reuniones, Formación, Gestión y Comercial. Cualquier interno activo imputa en él
 * sin ser miembro. El gestor principal es el primer admin.
 *
 * Idempotente: se reconoce por su código (Project::INTERNAL_CODE), también si se archivó o borró,
 * y sus tareas solo se crean si el proyecto no tiene ninguna (contando las borradas): así, volver
 * a ejecutar app:install nunca recrea una tarea que el admin haya renombrado o eliminado.
 */
class InternalProjectSeeder extends Seeder
{
    public const string NAME = 'Interno – Agencia';

    public const string COLOR = '#56667A';

    /**
     * Tipo de tarea por defecto de cada tarea interna (si existe).
     */
    private const array TASK_TYPES = [
        'Reuniones' => 'Reunión',
        'Gestión' => 'Gestión',
    ];

    /**
     * @param  int|null  $ownerId  Gestor principal; por defecto, el primer admin activo. (Un id y no
     *                             un User: el contenedor inyectaría un modelo vacío.)
     */
    public function run(?int $ownerId = null): void
    {
        $owner = $ownerId !== null
            ? User::query()->find($ownerId)
            : User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();

        if ($owner === null) {
            return;
        }

        DB::transaction(function () use ($owner): void {
            /** @var Project|null $project */
            $project = Project::withTrashed()->where('code', Project::INTERNAL_CODE)->first();

            if ($project === null) {
                $project = Project::query()->create([
                    'client_id' => null,
                    'name' => self::NAME,
                    'code' => Project::INTERNAL_CODE,
                    'description' => null,
                    'color' => self::COLOR,
                    'billing_type' => BillingType::Internal,
                    'status' => ProjectStatus::Active,
                    'start_date' => LocalTime::todayString(),
                    'owner_user_id' => $owner->id,
                ]);

                $project->addMember($owner, isManager: true);
            }

            $this->ensureTasks($project);
        });
    }

    private function ensureTasks(Project $project): void
    {
        if (Task::withTrashed()->where('project_id', $project->id)->exists()) {
            return;
        }

        $types = TaskType::query()->whereIn('name', array_values(self::TASK_TYPES))->pluck('id', 'name');
        $position = 0;

        TaskStatus::ensureDefaults();
        $status = TaskStatus::defaultStatus();

        foreach (Project::INTERNAL_TASKS as $title) {
            $typeName = self::TASK_TYPES[$title] ?? null;

            Task::query()->create([
                'project_id' => $project->id,
                'title' => $title,
                'task_type_id' => $typeName !== null ? ($types[$typeName] ?? null) : null,
                'status_id' => $status->id,
                'is_billable' => false,
                'position' => $position++,
                'created_by' => $project->owner_user_id,
            ]);
        }
    }
}
