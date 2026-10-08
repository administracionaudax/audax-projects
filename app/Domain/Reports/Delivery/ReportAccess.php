<?php

namespace App\Domain\Reports\Delivery;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * ¿Puede $user ver ahora el informe de un ReportRequest? (D-141). Las mismas comprobaciones que
 * hace la ruta de cada informe (ReportKind::routeName), con sus políticas, para:
 * - rechazar con 403 un envío o una programación de un informe que no ve,
 * - pausar un envío programado si su propietario ha perdido el acceso (reports:send-scheduled).
 * Además, la cuenta tiene que estar activa y ser de la plantilla: ni clientes ni colaboradores
 * externos (D-134). Un parámetro de ruta que falta o no existe cuenta como «sin acceso»: así no
 * se distingue lo que no existe de lo que no se puede ver.
 *
 * ReportFileGenerator (entrega 9.2) vuelve a comprobar el acceso al generar.
 */
final class ReportAccess
{
    /**
     * Parámetros de ruta de cada informe (los de routes/app/reports.php).
     *
     * @return list<string>
     */
    public static function routeParams(ReportKind $kind): array
    {
        return match ($kind) {
            ReportKind::Department => ['department'],
            ReportKind::Person => ['user'],
            ReportKind::Client => ['client'],
            ReportKind::Project, ReportKind::ProjectHours => ['project'],
            ReportKind::HourBank => ['project', 'hourBank'],
            ReportKind::Weekly => ['cycle'],
            default => [],
        };
    }

    public function allows(ReportRequest $request, User $user): bool
    {
        try {
            $this->authorize($request, $user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(ReportRequest $request, User $user): void
    {
        if (! $user->isActive() || ! $user->isInternal() || $user->isCollaborator()) {
            throw new AuthorizationException;
        }

        $gate = Gate::forUser($user);

        match ($request->kind) {
            ReportKind::Direction => $gate->authorize('viewDirectionReport', Department::class),
            ReportKind::Department => $gate->authorize('viewReport', $this->model(Department::class, $request, 'department')),
            ReportKind::Person => $gate->authorize('viewReport', $this->model(User::class, $request, 'user')),
            ReportKind::Client => $gate->authorize('viewReport', $this->model(Client::class, $request, 'client')),
            ReportKind::Project => $this->authorizeProject($request, $user),
            ReportKind::Billing => $gate->authorize('viewBilling', Client::class),
            ReportKind::Detail => $gate->authorize('viewDetailReport', TimeEntry::class),
            ReportKind::Hours => $gate->authorize('exportHours', TimeEntry::class),
            ReportKind::ProjectHours => $gate->authorize('view', $this->model(Project::class, $request, 'project')),
            ReportKind::HourBank => $gate->authorize('downloadPdf', $this->hourBank($request)),
            // Encendida de verdad: en modo de prueba (D-239) la Weekly no se envía ni se programa.
            ReportKind::Weekly => AppModules::enabled(AppModule::Weeklies)
                ? $gate->authorize('view', $this->model(WeeklyCycle::class, $request, 'cycle'))
                : throw new AuthorizationException,
            // Vendido frente a real (Fase 12, D-390): como la Weekly, solo con el módulo encendido de verdad.
            ReportKind::SoldVsActual => AppModules::enabled(AppModule::Billing)
                ? $gate->authorize('view-sold-vs-actual')
                : throw new AuthorizationException,
        };
    }

    /**
     * El informe de un proyecto (viewReport) y, en su versión para el cliente (D-241 y D-242), solo
     * quien ve todas sus horas: un admin o quien gestiona el proyecto.
     *
     * @throws AuthorizationException
     */
    private function authorizeProject(ReportRequest $request, User $user): void
    {
        $project = $this->model(Project::class, $request, 'project');
        Gate::forUser($user)->authorize('viewReport', $project);

        if (ReportVersion::fromQuery($request->query) === ReportVersion::Client && ! $user->isAdmin() && ! $user->isManagerOf($project)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     *
     * @throws AuthorizationException
     */
    private function model(string $class, ReportRequest $request, string $param): Model
    {
        $id = $request->routeParams[$param] ?? null;

        if (! is_numeric($id)) {
            throw new AuthorizationException;
        }

        $model = $class::query()->find((int) $id);

        if (! $model instanceof $class) {
            throw new AuthorizationException;
        }

        return $model;
    }

    /**
     * La bolsa siempre del proyecto de la URL (scopeBindings en la ruta).
     *
     * @throws AuthorizationException
     */
    private function hourBank(ReportRequest $request): HourBank
    {
        $project = $this->model(Project::class, $request, 'project');
        $bank = $this->model(HourBank::class, $request, 'hourBank');

        if ($bank->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        return $bank;
    }
}
