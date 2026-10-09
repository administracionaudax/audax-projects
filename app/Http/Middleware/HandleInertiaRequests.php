<?php

namespace App\Http\Middleware;

use App\Domain\Access\CollaboratorAccess;
use App\Domain\Billing\BillingNav;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Navigation\NavSections;
use App\Domain\People\ClockState;
use App\Domain\People\PeopleAccess;
use App\Domain\People\PeopleDocuments;
use App\Domain\People\TeamWorkday;
use App\Domain\Portal\Projects\PortalShell;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyAway;
use App\Enums\MonthCloseStatus;
use App\Http\Resources\FinancialResource;
use App\Models\Absence;
use App\Models\ActiveTimer;
use App\Models\Client;
use App\Models\ClockCorrection;
use App\Models\MonthClose;
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
                // Previsión (D-284 y D-306): la global (admins, responsables y manage-forecast) y la propia.
                'viewForecast' => $user ? Gate::forUser($user)->allows('view-forecast') : false,
                'useForecast' => $user ? Gate::forUser($user)->allows('use-forecast') : false,
                // Registro de jornada (Fase 11, D-342): usar el módulo, fichar, ver la jornada del equipo
                // y la bandeja (responsables y RR. HH.) y gestionar RR. HH. (manage-people).
                'usePeople' => $user ? Gate::forUser($user)->allows('use-people') : false,
                'clock' => $user ? Gate::forUser($user)->allows('clock') : false,
                'viewPeopleTeam' => $user ? Gate::forUser($user)->allows('view-people-team') : false,
                'managePeople' => $user ? Gate::forUser($user)->allows('manage-people') : false,
                // R2 (D-355): informes, Inspección y documentos de RR. HH. con el módulo visible.
                'managePeopleRegister' => $user ? Gate::forUser($user)->allows('manage-people-register') : false,
                // Facturación (Fase 12, D-391): facturas e importes (view-financials), el informe
                // «Vendido frente a real» (también en horas) y «Sincronizar ahora» (admins).
                'viewBilling' => $user ? Gate::forUser($user)->allows('view-billing') : false,
                'viewSoldVsActual' => $user ? Gate::forUser($user)->allows('view-sold-vs-actual') : false,
                'syncHolded' => $user ? Gate::forUser($user)->allows('sync-holded') : false,
                // Horas para facturar (D-045), en Facturación desde D-401 pero sin el módulo (D-402).
                'exportBillingHours' => $user ? Gate::forUser($user)->allows('viewBilling', Client::class) : false,
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
            // Secciones plegadas de la barra lateral (D-260), sin consultas (columna del usuario).
            'navCollapsed' => fn (): array => NavSections::collapsedFor($user),
            // Registro de jornada (Fase 11, D-333 y D-341): el botón de fichar de la cabecera y el
            // contador de «Pendientes». null si no usa el módulo.
            'people' => fn (): ?array => $this->people($user),
            // Facturación (D-405 y D-409): el contador de «Por revisar» y la última lectura de Holded,
            // solo con view-billing (null si no). En caché un minuto: son datos de toda la agencia.
            'billingNav' => fn (): ?array => BillingNav::for($user),
        ];
    }

    /**
     * @return array{clock: array<string, mixed>|null, pending: int, pending_close: array{id: int, month: string}|null, unread_documents: int}|null
     */
    private function people(User $user): ?array
    {
        if (! PeopleAccess::uses($user)) {
            return null;
        }

        $clock = null;

        if (PeopleAccess::subject($user)) {
            $state = ClockState::of($user);
            $since = $state->since();
            $running = $state->runningSince();

            $clock = [
                'status' => $state->status->value,
                'since' => $since?->utc()->toIso8601ZuluString(),
                'running_since' => $running?->utc()->toIso8601ZuluString(),
                // Lo trabajado hoy sin el tramo en curso: la cabecera le suma el tramo con su reloj.
                'worked_seconds' => $state->closedSecondsToday(),
                'work_mode' => ($state->current?->lastMode() ?? $state->lastMode)?->value,
                'unclosed_date' => $state->unclosedDate,
                'server_now' => $state->now->utc()->toIso8601ZuluString(),
            ];
        }

        // Correcciones que esperan mi decisión: las del equipo (responsables y RR. HH.) y las que me
        // proponen a mí.
        $pending = ClockCorrection::query()->pending()->where('user_id', $user->id)->whereColumn('proposed_by', '!=', 'user_id')->count();

        if (PeopleAccess::viewsTeam($user)) {
            $pending += app(TeamWorkday::class)->pendingFor($user);
        }

        // R2 (D-346 y D-354): el resumen del mes que espera mi respuesta y los documentos de RR. HH.
        // que aún no he leído (el aviso de Mi jornada y la insignia de Mi registro y Documentos).
        $close = MonthClose::query()->where('user_id', $user->id)->where('status', MonthCloseStatus::Pending->value)->orderByDesc('month')->first(['id', 'month']);

        return [
            'clock' => $clock,
            'pending' => $pending,
            'pending_close' => $close === null ? null : ['id' => $close->id, 'month' => $close->monthKey()],
            'unread_documents' => count(app(PeopleDocuments::class)->unreadFor($user)),
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
            ->with([
                'task' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'project_id'])->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name'])]),
                'dayPlanItem' => fn ($query) => $query->select(['id', 'text']),
            ])
            ->find($user->id);

        return $timer === null ? null : [
            'task_id' => $timer->task_id,
            'task_title' => $timer->task->title,
            'project_id' => $timer->task->project_id,
            'project_code' => $timer->task->project->code,
            'project_name' => $timer->task->project->name,
            'started_at' => $timer->started_at->toIso8601ZuluString(),
            'description' => $timer->description,
            // Plan del día (D-254): la línea desde la que se arrancó («en «Creatividades…»»).
            'day_plan_item' => $timer->dayPlanItem === null ? null : ['id' => $timer->dayPlanItem->id, 'text' => $timer->dayPlanItem->text],
        ];
    }
}
