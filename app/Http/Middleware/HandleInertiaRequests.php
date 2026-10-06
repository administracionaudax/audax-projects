<?php

namespace App\Http\Middleware;

use App\Domain\Access\CollaboratorAccess;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Portal\Projects\PortalShell;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyAway;
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
            ...($user !== null && $user->isInternal() ? $this->internalProps($request, $user) : []),
            // Portal (Fase 5, D-067): identidad de la empresa y proyectos abiertos al portal.
            ...($user !== null && $user->isClient() ? ['portal' => fn (): array => app(PortalShell::class)->for($user)] : []),
        ];
    }

    /**
     * Usuario y permisos de la interfaz.
     *
     * @return array{user: array<string, mixed>|null, can: array<string, bool>}
     */
    private function auth(Request $request, ?User $user): array
    {
        // Áreas que un colaborador externo no ve (D-134): las mismas rutas que cierra el middleware.
        $opens = fn (string $route): bool => $user !== null && (! $user->isCollaborator() || CollaboratorAccess::allowsRouteName($route));

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
                // Colaborador externo (D-134): solo sus proyectos, sus tareas y sus chats.
                'is_collaborator' => $user->isCollaborator(),
                // «Estoy fuera» de la Weekly (D-228), si sigue activo: la insignia del avatar.
                'weekly_away' => WeeklyAway::of($user),
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
                // Entradas de la navegación que un colaborador externo no tiene (D-134).
                'viewClients' => $user ? Gate::forUser($user)->allows('viewAny', Client::class) : false,
                'viewWorkload' => $opens('workload.index'),
                'viewAbsences' => $opens('absences.index'),
                'viewReports' => $opens('reports.index'),
                // La Weekly (Fase 10, D-147): usarla (internos de plantilla) y gestionarla.
                'useWeeklies' => $user ? Gate::forUser($user)->allows('use-weeklies') : false,
                'manageWeeklies' => $user ? Gate::forUser($user)->allows('manage-weeklies') : false,
                'viewAiUsage' => $user ? Gate::forUser($user)->allows('view-ai-usage') : false,
                // Plan del día (D-251): la plantilla interna con el módulo day_plan visible.
                'useDayPlan' => $user ? Gate::forUser($user)->allows('use-day-plan') : false,
            ],
        ];
    }

    /**
     * Temporizador activo, notificaciones sin leer y configuración (Fase 1), tiempo real y no leídos
     * del chat (Fase 6) y aviso de privacidad (Fase 7), en closures: consultas ligeras solo al pintar una página; la
     * configuración sale de la caché de ajustes.
     *
     * @return array<string, Closure>
     */
    private function internalProps(Request $request, User $user): array
    {
        return [
            'timer' => fn (): ?array => $this->timer($user),
            'notifications' => fn (): array => ['unread' => $user->unreadNotifications()->count()],
            'config' => fn (): array => [
                'hour_bank_thresholds' => app(HourBankLedger::class)->thresholds(),
                'timer_warning_hours' => (int) Setting::get('timer_warning_hours', 10),
                'timer_rounding_minutes' => (int) Setting::get('timer_rounding_minutes', 1),
                'description_required' => (bool) Setting::get('time_entry_description_required', false),
                // Chat (Fase 6): límites de los adjuntos y de los audios que se graban.
                'max_attachment_mb' => (int) Setting::get('max_attachment_mb', 50),
                'max_audio_seconds' => (int) Setting::get('max_audio_seconds', 300),
                // Fase 10 (D-151): módulos activos (F-177) y aviso global (F-178). Los módulos, los que
                // ve esta persona: en modo de prueba (D-239), un admin ve también los apagados, que
                // van en modules_preview.
                'modules' => AppModules::mapFor($user),
                'modules_preview' => AppModules::previewedBy($user),
                'global_banner' => Setting::get('global_banner'),
            ],
            // Aviso de privacidad pendiente de leer (D-075): sin consultas (ajustes en caché).
            'privacy' => fn (): array => [
                'needs_acknowledgement' => app(PrivacyNotice::class)->needsAcknowledgement($user),
            ],
            'realtime' => fn (): ?array => $this->realtime(),
            // Modo de prueba (D-239): la página es de un módulo apagado que solo ve un admin (lo marca
            // el middleware `module:…`, que ya ha pasado cuando se resuelve el closure).
            'module_preview' => fn (): bool => (bool) $request->attributes->get(EnsureModuleEnabled::PREVIEW_ATTRIBUTE, false),
            // Chat (Fase 6, C1): total sin leer de la entrada Chat de la navegación (una consulta).
            'chat' => fn (): array => ['unread' => app(ConversationDirectory::class)->unreadTotal($user)],
            // La Weekly (Fase 10, F-003): mi weekly pendiente de la semana activa, para el contador de
            // «Mi espacio» (en caché 5 minutos por persona y semana; 0 a quien no la escribe).
            'weeklies' => fn (): array => ['pending' => app(MyWeeklyStatus::class)->pendingCount($user)],
            // Google Sheets (Fase 9, D-142): sin credenciales, o para un colaborador externo (D-134),
            // la opción no se ofrece. La conexión, solo si se ofrece (una consulta).
            'integrations' => fn (): array => $this->integrations($user),
        ];
    }

    /**
     * @return array{google_sheets: bool, google_connected: bool}
     */
    private function integrations(User $user): array
    {
        $sheets = GoogleOAuth::configured() && ! $user->isCollaborator();

        return [
            'google_sheets' => $sheets,
            'google_connected' => $sheets && $user->googleConnection()->exists(),
        ];
    }

    /**
     * Conexión de Echo con Reverb para el navegador (Fase 6, config/realtime.php). null si el
     * tiempo real está apagado: la interfaz sigue funcionando con consultas periódicas.
     *
     * @return array{key: string, host: string, port: int, scheme: 'http'|'https'}|null
     */
    private function realtime(): ?array
    {
        $key = config('realtime.key');

        if (! config('realtime.enabled') || ! is_string($key) || $key === '') {
            return null;
        }

        return [
            'key' => $key,
            'host' => (string) config('realtime.host'),
            'port' => (int) config('realtime.port'),
            'scheme' => config('realtime.scheme') === 'http' ? 'http' : 'https',
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
