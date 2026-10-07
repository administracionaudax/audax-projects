<?php

namespace App\Providers;

use App\Domain\Absences\LeaveCalendar;
use App\Domain\DayPlan\DayPlanAccess;
use App\Domain\Forecast\ForecastAccess;
use App\Domain\People\PeopleAccess;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Gates globales que un colaborador externo nunca tiene (D-134): los permisos de
     * Permission::cases() y las de bolsas, aprobaciones, bloqueo, Horizon y la Weekly (D-147).
     */
    public const array COLLABORATOR_DENIED = [
        'manage-users',
        'manage-settings',
        'view-financials',
        'view-hour-banks',
        'approve-time',
        'lock-time',
        'viewHorizon',
        'manage-weeklies',
        'use-weeklies',
        'manage-help',
        'view-ai-usage',
        'view-person-ai-summary',
        'manage-forecast',
        'view-forecast',
        'use-forecast',
        'manage-people',
        'use-people',
        'clock',
        'view-people-team',
        'manage-people-register',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Días especiales del calendario laboral (Fase 11, R3): leídos una vez por petición o trabajo.
        $this->app->scoped(LeaveCalendar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (self::guardsRuntime($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            $this->guardSharedRedis();
        }

        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Permisos globales (SPEC §5). Los permisos por proyecto están en app/Policies.
     */
    protected function configureGates(): void
    {
        // Un usuario desactivado no puede nada, aunque conserve sus roles (SPEC §14).
        Gate::before(fn (User $user): ?bool => $user->isActive() ? null : false);

        // Un colaborador externo (D-134) nunca ve datos económicos ni bolsas, no aprueba ni bloquea
        // horas y no administra nada, aunque alguien le diera el permiso por error.
        Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, self::COLLABORATOR_DENIED, true) && $user->isCollaborator() ? false : null);

        // Una gate por permiso global (config permission.register_permission_check_method = false).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->checkPermissionTo($permission->value));
        }

        // Vista global de bolsas: admin, responsables y gestores de algún proyecto (D-005, D-035).
        Gate::define('view-hour-banks', fn (User $user): bool => $user->hasAnyRole([
            Role::Admin->value,
            Role::DepartmentManager->value,
        ]) || $user->managedProjects()->exists());

        // Aprobaciones de horas (/horas/aprobaciones): responsables y admins (D-020).
        Gate::define('approve-time', fn (User $user): bool => $user->hasAnyRole([
            Role::Admin->value,
            Role::DepartmentManager->value,
        ]));

        // Bloquear y desbloquear horas al facturar: solo admins (SPEC §7, D-034).
        Gate::define('lock-time', fn (User $user): bool => $user->isAdmin());

        // La Weekly (Fase 10, D-147). La usan (escriben la suya, ven las semanas, los informes, el
        // estado de proyectos, la ayuda, las sugerencias y el asistente) los internos de plantilla:
        // admin, responsables y empleados. Nunca un colaborador externo (D-134) ni un cliente.
        Gate::define('use-weeklies', fn (User $user): bool => $user->writesWeeklies());

        // Plan del día (D-251): la plantilla interna (admin, responsables y empleados), nunca un
        // colaborador externo ni un cliente, con el módulo day_plan visible para esa persona.
        Gate::define('use-day-plan', fn (User $user): bool => DayPlanAccess::uses($user));

        // Previsión (D-284), detrás del módulo forecast: la propia carga la ve toda la plantilla (P8),
        // la previsión global los admins, los responsables y quien tenga manage-forecast (P4).
        Gate::define('use-forecast', fn (User $user): bool => ForecastAccess::enabledFor($user));
        Gate::define('view-forecast', fn (User $user): bool => ForecastAccess::views($user));

        // Registro de jornada (Fase 11, D-330, D-331 y D-342), detrás del módulo people: lo usa la
        // plantilla interna; ficha quien está sujeto al registro; «Jornada del equipo» y «Pendientes»,
        // los responsables y RR. HH. (manage-people). Quién ve el registro de quién: PeopleAccess.
        Gate::define('use-people', fn (User $user): bool => PeopleAccess::uses($user));
        Gate::define('clock', fn (User $user): bool => PeopleAccess::clocks($user));
        Gate::define('view-people-team', fn (User $user): bool => PeopleAccess::viewsTeam($user));
        // R2 (D-355): informes, exportación para la Inspección, sus accesos y los documentos: RR. HH.
        Gate::define('manage-people-register', fn (User $user): bool => PeopleAccess::managesRegister($user));

        // Contenido del centro de ayuda (F-158): quien gestiona la Weekly (D-147).
        Gate::define('manage-help', fn (User $user): bool => $user->checkPermissionTo(Permission::ManageWeeklies->value));

        // Página «Uso de IA» (F-180): solo admins.
        Gate::define('view-ai-usage', fn (User $user): bool => $user->isAdmin());

        // Resúmenes de desempeño y de actividad por persona hechos con IA (F-144, F-145, D-147): el
        // admin y los responsables de esa persona (canSeeAbsencesOf, D-088), nunca un compañero ni
        // la propia persona (D-151).
        Gate::define('view-person-ai-summary', fn (User $user, User $subject): bool => $user->canSeeAbsencesOf($subject)
            && ($user->isAdmin() || $user->id !== $subject->id));

        // Datos económicos (costes, tarifas, rentabilidad): la gate view-financials definida arriba
        // exige el permiso del mismo nombre, que el admin recibe por defecto (RolesAndPermissionsSeeder).
    }

    /**
     * Comandos de consola que mantienen conexiones con Redis (colas, Horizon, scheduler, Reverb).
     *
     * @var list<string>
     */
    public const array LONG_RUNNING_COMMANDS = [
        'horizon', 'horizon:work', 'horizon:supervisor',
        'queue:work', 'queue:listen',
        'schedule:work', 'schedule:run',
        'reverb:start',
    ];

    /**
     * La guarda de Redis se aplica a las peticiones web y a los procesos de larga duración, que son
     * los que usarían el Redis compartido. No a comandos de mantenimiento como package:discover,
     * que Composer lanza sin .env (y por tanto como "production") en una instalación limpia o en la CI.
     */
    public static function guardsRuntime(bool $runningInConsole, ?string $command): bool
    {
        return ! $runningInConsole || in_array($command, self::LONG_RUNNING_COMMANDS, true);
    }

    /**
     * El servidor tiene un Redis compartido en el 6379, sin contraseña y con datos de otras webs
     * (docs/SERVIDOR.md §5). La app solo puede usar su Valkey propio: si la configuración apunta
     * al 6379 o no lleva contraseña, se aborta el arranque en lugar de caer en silencio en el compartido.
     */
    public function guardSharedRedis(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        /** @var array<string, mixed> $redis */
        $redis = (array) config('database.redis', []);

        foreach ($redis as $connection => $settings) {
            if (in_array($connection, ['client', 'options', 'clusters'], true) || ! is_array($settings)) {
                continue;
            }

            $port = (string) ($settings['port'] ?? '');
            $password = (string) ($settings['password'] ?? '');

            if ($port === '6379' || $password === '') {
                throw new RuntimeException("Redis [{$connection}] mal configurado: debe usarse el Valkey propio (puerto 16379, con contraseña).");
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Consultas N+1 (SPEC §19): en local y en los tests, cargar una relación sin eager loading falla.
        Model::preventLazyLoading(! app()->isProduction());

        // Los Resources se envían tal cual a Inertia, sin el envoltorio {data: …} (también los
        // anidados): el contrato es resources/js/types/domain.ts. Las colecciones PAGINADAS siguen
        // llegando como {data, links, meta}.
        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Sin ->uncompromised(): consultaría la API externa de Have I Been Pwned con un fragmento del
        // SHA-1 de cada contraseña, y el SPEC §15 prohíbe enviar datos a terceros (todo autoalojado).
        Password::defaults(fn (): ?Password => app()->environment(['local', 'testing'])
            ? null
            : Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols(),
        );
    }
}
