<?php

namespace App\Http\Middleware;

use App\Domain\HourBanks\HourBankLedger;
use App\Http\Resources\FinancialResource;
use App\Models\Absence;
use App\Models\ActiveTimer;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props compartidas con todas las páginas (contrato con resources/js/types).
     *
     * Las que consultan la base de datos van en closures (PERF-03): Inertia solo las resuelve al
     * pintar una respuesta Inertia (y, en una recarga parcial, solo las que se piden). Los
     * endpoints JSON (la campana, los buscadores) y las acciones que acaban en una redirección
     * no las calculan.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => $this->auth($request, $user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            ...($user !== null && $user->isInternal() ? $this->internalProps($user) : []),
        ];
    }

    /**
     * Usuario y permisos de la interfaz.
     *
     * @return array{user: array<string, mixed>|null, can: array<string, bool>}
     */
    private function auth(Request $request, ?User $user): array
    {
        return [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'theme_preference' => $user->theme_preference,
                'two_factor_enabled' => ! is_null($user->two_factor_confirmed_at),
                'roles' => $user->getRoleNames()->values()->all(),
                'is_client' => $user->isClient(),
            ] : null,
            'can' => [
                'viewHourBanks' => $user ? Gate::forUser($user)->allows('view-hour-banks') : false,
                'viewAdmin' => $user?->hasRole('admin') ?? false,
                'viewFinancials' => FinancialResource::financialsVisibleTo($request),
                'createClients' => $user ? Gate::forUser($user)->allows('create', Client::class) : false,
                'createProjects' => $user ? Gate::forUser($user)->allows('create', Project::class) : false,
                'approveTime' => $user ? Gate::forUser($user)->allows('approve-time') : false,
                'lockTime' => $user ? Gate::forUser($user)->allows('lock-time') : false,
                'manageUsers' => $user ? Gate::forUser($user)->allows('manage-users') : false,
                'manageSettings' => $user ? Gate::forUser($user)->allows('manage-settings') : false,
                // «Ausencias del equipo» (aprobar y registrar): responsables y admins (D-049).
                'viewTeamAbsences' => $user ? Gate::forUser($user)->allows('viewTeam', Absence::class) : false,
            ],
        ];
    }

    /**
     * Temporizador activo, notificaciones sin leer y configuración (Fase 1), en closures: dos
     * consultas ligeras solo al pintar una página; la configuración sale de la caché de ajustes.
     *
     * @return array<string, Closure>
     */
    private function internalProps(User $user): array
    {
        return [
            'timer' => fn (): ?array => $this->timer($user),
            'notifications' => fn (): array => ['unread' => $user->unreadNotifications()->count()],
            'config' => fn (): array => [
                'hour_bank_thresholds' => app(HourBankLedger::class)->thresholds(),
                'timer_warning_hours' => (int) Setting::get('timer_warning_hours', 10),
                'timer_rounding_minutes' => (int) Setting::get('timer_rounding_minutes', 1),
                'description_required' => (bool) Setting::get('time_entry_description_required', false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function timer(User $user): ?array
    {
        $timer = ActiveTimer::query()
            ->with(['task' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'project_id'])->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name'])])])
            ->find($user->id);

        return $timer === null ? null : [
            'task_id' => $timer->task_id,
            'task_title' => $timer->task->title,
            'project_id' => $timer->task->project_id,
            'project_code' => $timer->task->project->code,
            'project_name' => $timer->task->project->name,
            'started_at' => $timer->started_at->toIso8601ZuluString(),
            'description' => $timer->description,
        ];
    }
}
