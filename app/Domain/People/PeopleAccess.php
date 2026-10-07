<?php

namespace App\Domain\People;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClockCorrection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quién ficha y quién ve qué del registro de jornada (PLAN-FASE-11 §9; D-331 y D-342):
 *
 * - **Ficha** la plantilla interna (admin, responsables y empleados), activa y **sujeta al
 *   registro** (lo está salvo que RR. HH. diga lo contrario con un motivo). Nunca un colaborador
 *   externo ni un cliente: no son plantilla (D-331). Solo con el módulo `people` visible.
 * - **Ve el registro** de una persona: ella misma, su responsable (el de su departamento) y quien
 *   tiene `manage-people` (RR. HH. y los admins). Nadie más, tampoco un compañero (L-11).
 * - **Propone una corrección**: la propia persona, su responsable o RR. HH.
 * - **La acepta o la rechaza** la otra parte (doble conformidad, D-335): si la propone la persona,
 *   su responsable o RR. HH.; si la propone su responsable o RR. HH., la persona. Nadie decide una
 *   propuesta suya ni una corrección de su propio registro que haya propuesto él.
 * - **R2 (D-355)**: confirma o no su cierre mensual solo la persona; lo desconfirma, clasifica sus
 *   horas extra y anota movimientos de su saldo de horas su responsable o RR. HH., nunca ella
 *   misma (tampoco un responsable o un admin lo suyo). Los informes, la exportación para la
 *   Inspección, sus accesos y los documentos de RR. HH., solo RR. HH. (`manage-people`).
 */
final class PeopleAccess
{
    /** ¿Usa el módulo (ve sus pantallas)? La plantilla interna con el módulo visible. */
    public static function uses(?User $user): bool
    {
        return self::staff($user) && AppModules::visibleTo($user, AppModule::People);
    }

    /** ¿Es de la plantilla interna (sin mirar el módulo ni si está sujeta al registro)? */
    public static function staff(?User $user): bool
    {
        return $user !== null
            && $user->isActive()
            && ! $user->isCollaborator()
            && ! $user->isClient()
            && $user->writesWeeklies();
    }

    /**
     * ¿Es de la plantilla interna, activa o no? Para los datos laborales: la retención por litigio
     * de quien ya se fue (D-348).
     */
    public static function internalStaff(User $user): bool
    {
        return ! $user->isCollaborator() && ! $user->isClient() && $user->writesWeeklies();
    }

    /** ¿Está sujeta al registro de jornada? Sin datos laborales, sí (lo conservador, D-331). */
    public static function subject(User $user): bool
    {
        if (! self::staff($user)) {
            return false;
        }

        $profile = $user->relationLoaded('employmentProfile')
            ? $user->employmentProfile
            : $user->employmentProfile()->first(['id', 'user_id', 'subject_to_register']);

        return $profile === null || $profile->subject_to_register;
    }

    /** ¿Ficha? Plantilla sujeta al registro con el módulo visible. */
    public static function clocks(?User $user): bool
    {
        return $user !== null && self::uses($user) && self::subject($user);
    }

    /** RR. HH. (`manage-people`): ve y gestiona el registro de toda la plantilla. */
    public static function managesAll(User $user): bool
    {
        return $user->isActive() && ! $user->isCollaborator() && $user->checkPermissionTo(Permission::ManagePeople->value);
    }

    /** ¿Ve «Jornada del equipo» y «Pendientes»? Responsables y RR. HH., con el módulo visible. */
    public static function viewsTeam(User $user): bool
    {
        return self::uses($user) && (self::managesAll($user) || $user->isDepartmentManager());
    }

    /** ¿Ve el registro de $subject? Ella misma, su responsable o RR. HH. */
    public static function seesRegisterOf(User $viewer, User $subject): bool
    {
        if (! self::staff($viewer) || ! self::staff($subject)) {
            return false;
        }

        return $viewer->id === $subject->id || self::managesAll($viewer) || $viewer->supervises($subject);
    }

    /**
     * ¿Decide $actor por la empresa sobre el registro de $subject (desconfirmar su mes, clasificar
     * sus horas extra, anotar su saldo de horas)? Su responsable o RR. HH., nunca ella misma (D-355).
     */
    public static function decidesFor(User $actor, User $subject): bool
    {
        return self::staff($actor) && self::staff($subject) && $actor->id !== $subject->id
            && (self::managesAll($actor) || $actor->supervises($subject));
    }

    /** Informes, exportación para la Inspección, sus accesos y los documentos: RR. HH. (D-355). */
    public static function managesRegister(User $user): bool
    {
        return self::uses($user) && self::managesAll($user);
    }

    /**
     * La plantilla sujeta al registro (activa o no: quien ya se fue también tiene cierres y
     * registro), para los cierres, los informes y la Inspección.
     *
     * @return Builder<User>
     */
    public static function registerSubjects(): Builder
    {
        return User::query()
            ->role([Role::Admin->value, Role::DepartmentManager->value, Role::Employee->value])
            ->withoutCollaborators()
            ->whereDoesntHave('employmentProfile', fn (Builder $profile) => $profile->where('subject_to_register', false))
            ->orderBy('name');
    }

    /** ¿Puede $actor proponer una corrección del registro de $subject? */
    public static function proposesFor(User $actor, User $subject): bool
    {
        return self::seesRegisterOf($actor, $subject);
    }

    /** ¿Puede $actor aceptar o rechazar la corrección? Solo la otra parte, y mientras esté pendiente. */
    public static function decides(User $actor, ClockCorrection $correction, ?User $subject = null): bool
    {
        if ($correction->status->isFinal() || $actor->id === $correction->proposed_by || ! self::staff($actor)) {
            return false;
        }

        if (! $correction->proposedBySubject()) {
            // La propone su responsable o RR. HH.: solo la propia persona da su conformidad.
            return $actor->id === $correction->user_id;
        }

        $subject ??= User::query()->findOrFail($correction->user_id);

        return $actor->id !== $subject->id && (self::managesAll($actor) || $actor->supervises($subject));
    }

    /**
     * Personas cuyo registro ve $viewer en «Jornada del equipo»: todas (RR. HH.) o las de los
     * departamentos que dirige; solo plantilla interna activa sujeta al registro. Opcionalmente, de
     * un departamento.
     *
     * @return Builder<User>
     */
    public static function teamQuery(User $viewer, ?int $departmentId = null): Builder
    {
        return User::query()
            ->active()
            ->role([Role::Admin->value, Role::DepartmentManager->value, Role::Employee->value])
            ->withoutCollaborators()
            ->whereDoesntHave('employmentProfile', fn (Builder $profile) => $profile->where('subject_to_register', false))
            ->when(! self::managesAll($viewer), fn (Builder $query) => $query->whereIn('department_id', $viewer->managedDepartmentIds() ?: [0]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId));
    }

    /**
     * Departamentos por los que puede filtrar: todos (RR. HH.) o los que dirige.
     *
     * @return Collection<int, Department>
     */
    public static function departments(User $viewer): Collection
    {
        return Department::query()
            ->when(! self::managesAll($viewer), fn (Builder $query) => $query->whereKey($viewer->managedDepartmentIds() ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    /**
     * Quién puede dar la conformidad de la empresa a una corrección que propone $subject: los
     * responsables activos de su departamento y quien tiene `manage-people`, nunca ella misma. Para
     * avisarles (D-339).
     *
     * @return Collection<int, User>
     */
    public static function companyDeciders(User $subject): Collection
    {
        $managers = $subject->department_id === null ? new Collection : User::query()
            ->active()
            ->whereKeyNot($subject->id)
            ->whereHas('managedDepartments', fn (Builder $department) => $department->whereKey($subject->department_id))
            ->get();

        $hr = User::query()
            ->active()
            ->whereKeyNot($subject->id)
            ->withoutCollaborators()
            ->permission(Permission::ManagePeople->value)
            ->get();

        return $managers->merge($hr)->unique('id')->sortBy('name')->values();
    }
}
