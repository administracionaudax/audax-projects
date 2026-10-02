<?php

namespace App\Models;

use App\Auth\SessionTerminator;
use App\Enums\Role;
use App\Events\MembershipsChanged;
use App\Notifications\ResetPasswordNotification;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $department_id
 * @property int|null $client_id
 * @property string|null $hourly_cost
 * @property string|null $default_hourly_rate
 * @property bool $is_active
 * @property string $theme_preference
 * @property string $locale
 * @property array<string, mixed>|null $notification_preferences
 * @property int|null $privacy_acknowledged_version
 * @property Carbon|null $privacy_acknowledged_at
 * @property string|null $avatar_path
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar_url
 * @property-read Department|null $department
 * @property-read Client|null $client
 * @property-read ActiveTimer|null $activeTimer
 * @property-read ProjectMember|null $membership
 */
#[Fillable([
    'name',
    'email',
    'password',
    'department_id',
    'client_id',
    'hourly_cost',
    'default_hourly_rate',
    'is_active',
    'theme_preference',
    'locale',
    'notification_preferences',
    'avatar_path',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    // Datos económicos: solo con el permiso view-financials (SPEC §5). Ver revealFinancialsTo().
    'hourly_cost',
    'default_hourly_rate',
])]
class User extends Authenticatable
{
    /** Prefijo de la memoria por petición de pertenencia y gestión (PERF-04). */
    private const string MEMO_PREFIX = 'audax.membership.';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    public const array THEMES = ['light', 'dark', 'system'];

    /**
     * Atributos económicos que solo ve quien tiene view-financials.
     */
    public const array FINANCIAL_ATTRIBUTES = ['hourly_cost', 'default_hourly_rate'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'theme_preference' => 'system',
        'locale' => 'es',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'hourly_cost' => 'decimal:2',
            'default_hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
            'privacy_acknowledged_version' => 'integer',
            'privacy_acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Departamentos de los que es responsable (D-024).
     *
     * @return BelongsToMany<Department, $this>
     */
    public function managedDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_managers')->withTimestamps();
    }

    /**
     * ¿Es responsable de ese departamento?
     */
    public function managesDepartment(Department|int|null $department): bool
    {
        if ($department === null) {
            return false;
        }

        $id = $department instanceof Department ? $department->id : $department;

        return in_array($id, $this->managedDepartmentIds(), true);
    }

    /**
     * Cliente al que pertenece un usuario del portal (Fase 5).
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<WorkSchedule, $this>
     */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    /**
     * Proyectos de los que es miembro (con is_manager y alert_preferences en `membership`).
     *
     * @return BelongsToMany<Project, $this, ProjectMember, 'membership'>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->using(ProjectMember::class)
            ->as('membership')
            ->withPivot(['is_manager', 'alert_preferences'])
            ->withTimestamps();
    }

    /**
     * Proyectos que gestiona (principal o co-gestor, D-005).
     *
     * @return BelongsToMany<Project, $this, ProjectMember, 'membership'>
     */
    public function managedProjects(): BelongsToMany
    {
        return $this->projects()->wherePivot('is_manager', true);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_user_id');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasOne<ActiveTimer, $this>
     */
    public function activeTimer(): HasOne
    {
        return $this->hasOne(ActiveTimer::class);
    }

    /**
     * @return HasMany<Absence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * @return HasMany<TimesheetPeriod, $this>
     */
    public function timesheetPeriods(): HasMany
    {
        return $this->hasMany(TimesheetPeriod::class);
    }

    /**
     * Navegadores suscritos a los avisos Web Push (Fase 6, D-072).
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Rol de responsable de departamento (qué departamentos dirige lo marca el pivote, D-024).
     */
    public function isDepartmentManager(): bool
    {
        return $this->hasRole(Role::DepartmentManager->value);
    }

    /**
     * Ids de los departamentos que dirige (D-024).
     *
     * @return list<int>
     */
    public function managedDepartmentIds(): array
    {
        /** @var list<int> */
        return $this->memo('departments', fn (): array => $this->managedDepartments()->pluck('departments.id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    /**
     * Ids de los proyectos que gestiona (D-005).
     *
     * @return list<int>
     */
    public function managedProjectIds(): array
    {
        /** @var list<int> */
        return $this->memo('projects', fn (): array => $this->managedProjects()->pluck('projects.id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    public function isMemberOf(Project|int $project): bool
    {
        $id = $project instanceof Project ? $project->id : $project;

        return (bool) $this->memo("member.{$id}", fn (): bool => $this->projects()->whereKey($id)->exists());
    }

    public function isManagerOf(Project|int $project): bool
    {
        $id = $project instanceof Project ? $project->id : $project;

        return in_array($id, $this->managedProjectIds(), true);
    }

    /**
     * Memoria de las comprobaciones de gestión y pertenencia durante una petición (PERF-04): la
     * hoja semanal, el panel de la tarea y las políticas las repiten varias veces con el mismo
     * resultado. Se guarda en los atributos de la petición en curso (una petición nueva empieza
     * de cero) y solo mientras se atiende una ruta: los comandos, los jobs y el código que se
     * llama fuera de una petición consultan siempre. Cualquier cambio de miembros, gestores o
     * responsables la vacía (ProjectMember y User::forgetMemberships()).
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    private function memo(string $key, Closure $compute): mixed
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request || $request->route() === null || ! $this->exists) {
            return $compute();
        }

        $key = self::MEMO_PREFIX.$this->id.'.'.$key;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $compute());
        }

        return $request->attributes->get($key);
    }

    /**
     * Vacía la memoria de pertenencia y gestión de la petición en curso (tras cambiar miembros,
     * gestores de proyecto o responsables de departamento) y lo avisa (MembershipsChanged: la caché
     * de los informes se invalida).
     */
    public static function forgetMemberships(): void
    {
        MembershipsChanged::dispatch();

        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return;
        }

        foreach (array_keys($request->attributes->all()) as $key) {
            if (str_starts_with((string) $key, self::MEMO_PREFIX)) {
                $request->attributes->remove((string) $key);
            }
        }
    }

    /**
     * Gestionar un proyecto (tareas, bolsas, miembros, ajustes): admin, responsables y sus gestores
     * (D-022, plan de la Fase 1).
     */
    public function canManageProject(Project $project): bool
    {
        return $this->isAdmin() || $this->isDepartmentManager() || $this->isManagerOf($project);
    }

    /**
     * ¿Dirige el departamento de $other? (ve y aprueba sus horas, D-020 y D-021).
     */
    public function supervises(User $other): bool
    {
        return $other->department_id !== null && $this->managesDepartment($other->department_id);
    }

    /**
     * ¿Puede ver las horas de $other? Las suyas, las de su equipo o todas si es admin (D-021).
     * Los gestores ven además las horas de sus proyectos: eso se filtra por entrada, no por persona.
     */
    public function canSeeHoursOf(User $other): bool
    {
        return $this->id === $other->id || $this->isAdmin() || $this->supervises($other);
    }

    /**
     * ¿Puede saber de qué tipo son las ausencias de $other (una baja es un dato de salud)? La propia
     * persona, un admin o quien la supervisa (D-088). Los demás, como mucho, que ese día no está.
     */
    public function canSeeAbsencesOf(User $other): bool
    {
        return $this->id === $other->id || $this->isAdmin() || $this->supervises($other);
    }

    /**
     * El enlace de restablecimiento se envía por cola (SPEC §13).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Los clientes solo acceden al portal (SPEC §5 y §11).
     */
    public function isClient(): bool
    {
        return $this->hasRole(Role::Client->value);
    }

    public function isInternal(): bool
    {
        return ! $this->isClient();
    }

    /**
     * Al desactivar a un usuario se cierran todas sus sesiones y su «Recordarme» (SPEC §14): no
     * basta con que EnsureUserIsActive lo expulse en la siguiente petición.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                app(SessionTerminator::class)->destroyAll($user);
            }
        });
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin->value);
    }

    /**
     * Muestra los datos económicos en la serialización solo si $viewer puede verlos.
     */
    public function revealFinancialsTo(?User $viewer): static
    {
        if ($viewer !== null && Gate::forUser($viewer)->allows('view-financials')) {
            $this->makeVisible(self::FINANCIAL_ATTRIBUTES);
        }

        return $this;
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Usuarios internos (todos salvo los de rol cliente).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function internal(Builder $query): void
    {
        $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', Role::Client->value));
    }

    /**
     * URL del avatar mediante ruta firmada (los ficheros nunca son públicos, SPEC §15), o null.
     * La ruta avatars.show llega cuando se implemente la subida de avatares.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->avatar_path === null || ! Route::has('avatars.show')) {
                return null;
            }

            return URL::temporarySignedRoute('avatars.show', now()->addHour(), ['user' => $this->id]);
        });
    }
}
