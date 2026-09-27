<?php

namespace Tests\Feature\Workload\Concerns;

use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Escenario común de la vista Carga. Hoy es el martes 06/10/2026 (Madrid); la semana que viene va
 * del lunes 12/10 (festivo) al domingo 18/10. Jornada por defecto: 8 h de lunes a viernes.
 *
 * - Ana (admin, sin departamento).
 * - Diseño: Raúl (responsable), Elena y Lucía (vacaciones el 13 y el 14) y Olga (de baja en la app).
 * - Desarrollo: Marta (responsable), Pablo (formación de 4 h el 15) y Sergio (gestor de WEB).
 * - WEB (Acme, gestor Sergio; miembros Elena, Lucía y Pablo) y APP (Beta, gestora Marta; miembros
 *   Elena y Pablo).
 * - La semana que viene: Elena tiene WEB (20 h del 13 al 16) y APP (10 h del 13 al 14): 10 h el 13
 *   y el 14 (sobrecarga) y 5 h el 15 y el 16. Lucía, WEB (8 h del 13 al 16): 4 h el 15 y el 16.
 *   Pablo, APP (16 h del 12 al 16): 4 h el 13, 14, 15 y 16.
 * - Vencida: Elena, WEB, 5 h con entrega el 02/10 (van hoy).
 * - Sin planificar: Elena (WEB, sin estimación) y Pablo (APP, sin entrega).
 * - Sin asignar: una de Diseño, una de Desarrollo y otra sin departamento.
 */
trait BuildsWorkloadScenario
{
    /** @var array<string, User> */
    protected array $people = [];

    /** @var array<string, Department> */
    protected array $departments = [];

    /** @var array<string, Project> */
    protected array $projects = [];

    /** @var array<string, Task> */
    protected array $tasks = [];

    protected function buildWorkloadScenario(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Madrid'));
        TaskStatus::ensureDefaults();

        $this->departments = [
            'design' => Department::factory()->create(['name' => 'Diseño', 'color' => '#0171FF']),
            'dev' => Department::factory()->create(['name' => 'Desarrollo', 'color' => '#179FA5']),
        ];

        $person = fn (string $name, Role $role, ?string $department = null, array $attributes = []): User => User::factory()
            ->withRole($role)
            ->create(['name' => $name, 'department_id' => $department === null ? null : $this->departments[$department]->id, ...$attributes]);

        $this->people = [
            'ana' => $person('Ana Admin', Role::Admin),
            'raul' => $person('Raúl Responsable', Role::DepartmentManager, 'design'),
            'elena' => $person('Elena Empleada', Role::Employee, 'design'),
            'lucia' => $person('Lucía Martín', Role::Employee, 'design'),
            'olga' => $person('Olga Baja', Role::Employee, 'design', ['is_active' => false]),
            'marta' => $person('Marta Iglesias', Role::DepartmentManager, 'dev'),
            'pablo' => $person('Pablo Ruiz', Role::Employee, 'dev'),
            'sergio' => $person('Sergio Gómez', Role::Employee, 'dev'),
            'client' => $person('Cliente Portal', Role::Client),
        ];
        $this->departments['design']->managers()->attach($this->people['raul']->id);
        $this->departments['dev']->managers()->attach($this->people['marta']->id);

        Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
        Absence::factory()->approved()->between('2026-10-13', '2026-10-14')->create(['user_id' => $this->people['lucia']->id, 'type' => AbsenceType::Vacation]);
        Absence::factory()->approved()->partial(240)->between('2026-10-15', '2026-10-15')->create(['user_id' => $this->people['pablo']->id, 'type' => AbsenceType::Training]);

        $this->projects = [
            'web' => Project::factory()->create(['code' => 'WEB', 'name' => 'Web corporativa', 'client_id' => Client::factory()->create(['name' => 'Acme'])->id, 'owner_user_id' => $this->people['sergio']->id]),
            'app' => Project::factory()->create(['code' => 'APP', 'name' => 'App de citas', 'client_id' => Client::factory()->create(['name' => 'Beta'])->id, 'owner_user_id' => $this->people['marta']->id]),
        ];
        foreach (['elena', 'lucia', 'pablo'] as $key) {
            $this->projects['web']->addMember($this->people[$key]);
        }
        foreach (['elena', 'pablo'] as $key) {
            $this->projects['app']->addMember($this->people[$key]);
        }

        $task = fn (string $project, ?string $who, array $attributes): Task => Task::factory()->create([
            'project_id' => $this->projects[$project]->id,
            'assignee_user_id' => $who === null ? null : $this->people[$who]->id,
            ...$attributes,
        ]);

        $designType = TaskType::factory()->create(['name' => 'Diseño UI', 'department_id' => $this->departments['design']->id]);
        $devType = TaskType::factory()->create(['name' => 'Desarrollo', 'department_id' => $this->departments['dev']->id]);

        $this->tasks = [
            'elena_web' => $task('web', 'elena', ['title' => 'Maquetar la home', 'estimated_minutes' => 1200, 'start_date' => '2026-10-13', 'due_date' => '2026-10-16']),
            'elena_app' => $task('app', 'elena', ['title' => 'Pantalla de reservas', 'estimated_minutes' => 600, 'start_date' => '2026-10-13', 'due_date' => '2026-10-14']),
            'lucia_web' => $task('web', 'lucia', ['title' => 'Iconos', 'estimated_minutes' => 480, 'start_date' => '2026-10-13', 'due_date' => '2026-10-16']),
            'pablo_app' => $task('app', 'pablo', ['title' => 'API de citas', 'estimated_minutes' => 960, 'start_date' => '2026-10-12', 'due_date' => '2026-10-16']),
            'elena_overdue' => $task('web', 'elena', ['title' => 'Revisión atrasada', 'estimated_minutes' => 300, 'start_date' => '2026-09-28', 'due_date' => '2026-10-02']),
            'elena_unplanned' => $task('web', 'elena', ['title' => 'Textos legales', 'estimated_minutes' => null, 'due_date' => '2026-10-15']),
            'pablo_unplanned' => $task('app', 'pablo', ['title' => 'Notificaciones push', 'estimated_minutes' => 120, 'due_date' => null]),
            'unassigned_design' => $task('web', null, ['title' => 'Banner de campaña', 'estimated_minutes' => 240, 'due_date' => '2026-10-20', 'task_type_id' => $designType->id]),
            'unassigned_dev' => $task('app', null, ['title' => 'Corregir login', 'estimated_minutes' => 180, 'due_date' => '2026-10-01', 'task_type_id' => $devType->id]),
            'unassigned_none' => $task('web', null, ['title' => 'Reunión de arranque', 'estimated_minutes' => 60, 'due_date' => '2026-10-22']),
        ];
    }

    /**
     * Celda de una persona en la matriz de la respuesta (null si no está).
     *
     * @param  array<string, mixed>  $matrix
     * @return array<string, mixed>|null
     */
    protected function workloadCell(array $matrix, string $person, string $date): ?array
    {
        $index = array_search($date, array_column($matrix['columns'], 'key'), true);

        foreach ($matrix['groups'] as $group) {
            foreach ($group['people'] as $row) {
                if ($row['id'] === $this->people[$person]->id && $index !== false) {
                    return $row['cells'][$index];
                }
            }
        }

        return null;
    }

    /**
     * Nombres de las filas de la matriz, en orden.
     *
     * @param  array<string, mixed>  $matrix
     * @return list<string>
     */
    protected function workloadRowNames(array $matrix): array
    {
        $names = [];

        foreach ($matrix['groups'] as $group) {
            foreach ($group['people'] as $row) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }
}
