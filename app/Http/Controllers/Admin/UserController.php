<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\TextSearch;
use App\Domain\Admin\UserGuard;
use App\Domain\Admin\UserInviter;
use App\Domain\Privacy\PersonalDataExportList;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\Admin\UserRowResource;
use App\Http\Resources\Admin\WorkScheduleResource;
use App\Http\Resources\DepartmentResource;
use App\Models\ActiveTimer;
use App\Models\Department;
use App\Models\LoginEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios internos (SPEC §14): listado con búsqueda y filtros, alta por invitación y ficha con
 * sus datos, su jornada versionada y su estado. Gate manage-users (también en la ruta).
 * Los usuarios del portal (rol client) no se gestionan aquí: llegan en la Fase 5.
 */
class UserController extends Controller
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly UserGuard $guard,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage-users');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'rol' => ['nullable', 'string', Rule::in(UserRequest::assignableRoles())],
            'departamento' => ['nullable', 'string', 'regex:/^(\d+|ninguno)$/'],
            'estado' => ['nullable', 'string', Rule::in(['activos', 'inactivos', 'todos'])],
        ]);

        $status = $filters['estado'] ?? 'activos';
        $department = $filters['departamento'] ?? null;

        $users = User::query()
            ->internal()
            ->with(['department', 'roles'])
            ->addSelect(['last_login_at' => LoginEvent::query()
                ->select('created_at')
                ->whereColumn('login_events.user_id', 'users.id')
                ->where('succeeded', true)
                ->latest('created_at')
                ->limit(1),
            ])
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => TextSearch::apply($query, $term, ['users.name', 'users.email']))
            ->when($filters['rol'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($department === 'ninguno', fn (Builder $query) => $query->whereNull('department_id'))
            ->when($department !== null && $department !== 'ninguno', fn (Builder $query) => $query->where('department_id', (int) $department))
            ->when($status === 'activos', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactivos', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'users' => UserRowResource::collection($users),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'rol' => $filters['rol'] ?? null,
                'departamento' => $department,
                'estado' => $status,
            ],
            'departments' => $this->departments($request),
            'roles' => UserRequest::assignableRoles(),
            'canGrantAdmin' => $request->user()?->isAdmin() ?? false,
        ]);
    }

    public function store(UserRequest $request, UserInviter $inviter): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($request->role() === Role::Admin) {
            Gate::denyIf(! $actor->isAdmin(), __('admin.users.errors.grant_admin'));
        }

        $user = $inviter->invite($actor, $request->userData(), $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.users.invited', ['email' => $user->email])]);

        return to_route('admin.users.edit', $user);
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('manage-users');
        abort_if($user->isClient(), 404);

        /** @var User $actor */
        $actor = $request->user();

        $user->load(['department', 'roles']);
        $user->setAttribute('last_login_at', LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('succeeded', true)
            ->latest('created_at')
            ->value('created_at'));

        $schedules = $user->workSchedules()->orderByDesc('valid_from')->get();
        $timer = ActiveTimer::query()->find($user->id);

        return Inertia::render('admin/users/edit', [
            'user' => ResourceProps::item(UserRowResource::make($user), $request),
            'schedules' => ResourceProps::list(WorkScheduleResource::collection($schedules), $request),
            'departments' => $this->departments($request),
            'roles' => UserRequest::assignableRoles(),
            'openTasksCount' => Task::query()->open()->assignedTo($user)->count(),
            'hasActiveTimer' => $timer !== null,
            'can' => [
                'manage' => ! $user->isAdmin() || $actor->isAdmin(),
                'grantAdmin' => $actor->isAdmin(),
                'changeRole' => ! ($user->isAdmin() && ($actor->id === $user->id || $this->guard->isLastActiveAdmin($user))),
                'deactivate' => $user->is_active && $actor->id !== $user->id
                    && (! $user->isAdmin() || ($actor->isAdmin() && ! $this->guard->isLastActiveAdmin($user))),
                'viewFinancials' => Gate::allows('view-financials'),
            ],
            // Exportaciones de sus datos personales (D-075): solo el admin las pide y las descarga.
            'personalDataExports' => $actor->isAdmin() ? app(PersonalDataExportList::class)->for($user) : null,
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $role = $request->role();

        DB::transaction(function () use ($request, $actor, $user, $role): void {
            $this->guard->lockActiveAdmins();
            $this->guard->assertCanChangeRole($actor, $user, $role);

            $user->forceFill($request->userData())->save();

            if (! $user->hasRole($role->value)) {
                $user->syncRoles([$role->value]);

                // Solo responsables y admins pueden figurar como responsables de un departamento.
                if ($role === Role::Employee) {
                    $user->managedDepartments()->detach();
                    User::forgetMemberships();
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.users.updated')]);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function departments(Request $request): array
    {
        return ResourceProps::list(DepartmentResource::collection(Department::query()->orderBy('name')->get()), $request);
    }
}
