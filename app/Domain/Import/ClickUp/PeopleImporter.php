<?php

namespace App\Domain\Import\ClickUp;

use App\Domain\Access\CollaboratorOffboarding;
use App\Domain\Admin\WorkScheduleVersions;
use App\Domain\Projects\ProjectColors;
use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Personas de la importación (D-136), desde el fichero de personas:
 * - crea las cuentas que faltan como lo hace la administración (activas, con una contraseña
 *   aleatoria que nadie conoce y su jornada por defecto), SIN enviar la invitación,
 * - a las que ya existen (mismo correo) les actualiza el rol y el departamento,
 * - crea los departamentos que falten y añade a sus responsables (pivote department_managers).
 * Solo admins y responsables figuran como responsables (como en la administración); un
 * colaborador nunca. No se quita el rol de admin al último admin activo.
 */
final class PeopleImporter
{
    public function __construct(
        private readonly WorkScheduleVersions $schedules,
        private readonly CollaboratorOffboarding $offboarding,
    ) {}

    /**
     * @return array<string, PersonMatch> correo de ClickUp => persona (también las que no se importan)
     */
    public function import(PeopleFile $file, ImportReport $report): array
    {
        $matches = [];

        foreach ($file->people as $spec) {
            if (! $spec->import) {
                $matches[$spec->clickupEmail] = new PersonMatch($spec, null);
                $report->count('people', ImportReport::SKIPPED);

                continue;
            }

            $department = $spec->department !== null ? $this->department($spec->department, $report) : null;
            $user = User::query()->whereRaw('lower(email) = ?', [$spec->email])->first();

            if ($user === null) {
                $user = $this->create($spec, $department);
                $report->count('people', ImportReport::CREATED);
            } else {
                $changed = $this->update($user, $spec, $department, $report);
                $report->count('people', $changed ? ImportReport::UPDATED : ImportReport::UNCHANGED);
            }

            if ($spec->isDepartmentManager) {
                $this->addManager($user, $spec, $department, $report);
            }

            $matches[$spec->clickupEmail] = new PersonMatch($spec, $user);
        }

        return $matches;
    }

    private function create(PersonSpec $spec, ?Department $department): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $spec->name,
            'email' => $spec->email,
            'password' => Str::password(40),
            'department_id' => $department?->id,
            'is_active' => $spec->active,
        ])->save();

        $user->syncRoles([$spec->role->value]);
        $this->schedules->createDefault($user);

        return $user;
    }

    private function update(User $user, PersonSpec $spec, ?Department $department, ImportReport $report): bool
    {
        $changed = false;
        $role = $spec->role;

        if ($user->isAdmin() && $role !== Role::Admin && $this->isLastActiveAdmin($user)) {
            $report->warn("No se quita el rol de admin a la última cuenta de administración ({$user->name}).");
            $role = Role::Admin;
        }

        if (! $user->hasRole($role->value) || $user->roles()->count() !== 1) {
            $becomesCollaborator = $role === Role::Collaborator && ! $user->isCollaborator();
            $user->syncRoles([$role->value]);
            $changed = true;

            if ($role === Role::Employee || $role === Role::Collaborator) {
                $user->managedDepartments()->detach();
            }

            // Como en la administración (D-134): deja de ser co-gestor, suelta las tareas de los
            // proyectos de los que no es miembro y sale de sus directas y grupos.
            if ($becomesCollaborator) {
                $this->offboarding->becameCollaborator($user);
            }
        }

        if (! $spec->active && $user->is_active) {
            if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
                $report->warn("No se desactiva la última cuenta de administración ({$user->name}).");
            } else {
                $user->is_active = false;
                $user->save();
                $changed = true;
            }
        }

        if ($user->department_id !== $department?->id) {
            $user->department_id = $department?->id;
            $user->save();
            $changed = true;
        }

        return $changed;
    }

    private function addManager(User $user, PersonSpec $spec, ?Department $department, ImportReport $report): void
    {
        if (! $spec->canOwnProjects()) {
            $report->warn("{$spec->name} figura como responsable pero su rol ({$spec->role->label()}) no lo permite: no se le hace responsable.");

            return;
        }

        if ($department === null) {
            $report->warn("{$spec->name} figura como responsable pero no tiene departamento.");

            return;
        }

        $department->managers()->syncWithoutDetaching([$user->id]);
    }

    /**
     * El departamento con ese nombre (sin distinguir mayúsculas) o uno nuevo.
     */
    public function department(string $name, ImportReport $report): Department
    {
        $department = Department::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($department !== null) {
            return $department;
        }

        $default = collect(Department::DEFAULTS)->firstWhere('name', $name);

        $department = Department::query()->create([
            'name' => $name,
            'color' => is_array($default) ? $default['color'] : ProjectColors::next(Department::withTrashed()->count()),
        ]);
        $report->count('departments', ImportReport::CREATED);

        return $department;
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return User::role(Role::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
    }
}
