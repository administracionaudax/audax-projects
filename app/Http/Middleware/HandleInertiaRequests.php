<?php

namespace App\Http\Middleware;

use App\Domain\HourBanks\HourBankLedger;
use App\Models\ActiveTimer;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
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
            'auth' => [
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
                    'viewFinancials' => $user ? Gate::forUser($user)->allows('view-financials') : false,
                    'createClients' => $user ? Gate::forUser($user)->allows('create', Client::class) : false,
                    'createProjects' => $user ? Gate::forUser($user)->allows('create', Project::class) : false,
                    'approveTime' => $user ? Gate::forUser($user)->allows('approve-time') : false,
                    'lockTime' => $user ? Gate::forUser($user)->allows('lock-time') : false,
                    'manageUsers' => $user ? Gate::forUser($user)->allows('manage-users') : false,
                    'manageSettings' => $user ? Gate::forUser($user)->allows('manage-settings') : false,
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            ...($user !== null && $user->isInternal() ? $this->internalProps($user) : []),
        ];
    }

    /**
     * Temporizador activo, notificaciones sin leer y configuración (Fase 1). Dos consultas
     * ligeras por petición; la configuración sale de la caché de ajustes.
     *
     * @return array<string, mixed>
     */
    private function internalProps(User $user): array
    {
        $timer = ActiveTimer::query()
            ->with(['task' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'project_id'])->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name'])])])
            ->find($user->id);

        return [
            'timer' => $timer === null ? null : [
                'task_id' => $timer->task_id,
                'task_title' => $timer->task->title,
                'project_id' => $timer->task->project_id,
                'project_code' => $timer->task->project->code,
                'project_name' => $timer->task->project->name,
                'started_at' => $timer->started_at->toIso8601ZuluString(),
                'description' => $timer->description,
            ],
            'notifications' => [
                'unread' => $user->unreadNotifications()->count(),
            ],
            'config' => [
                'hour_bank_thresholds' => app(HourBankLedger::class)->thresholds(),
                'timer_warning_hours' => (int) Setting::get('timer_warning_hours', 10),
                'timer_rounding_minutes' => (int) Setting::get('timer_rounding_minutes', 1),
            ],
        ];
    }
}
