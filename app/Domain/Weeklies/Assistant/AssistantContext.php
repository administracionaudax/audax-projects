<?php

namespace App\Domain\Weeklies\Assistant;

use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Insights\ClientInsights;
use App\Domain\Weeklies\ProjectStatus\ProjectKindCode;
use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Enums\AppModule;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El contexto del asistente (F-147, D-146 y D-205), recortado como `query-knowledge-base` (6 semanas y
 * de 20 a 80 filas), pero construido SOLO con lo que puede ver quien pregunta:
 * - semanas, reportes enviados (nunca borradores) y el último informe: lo ve toda la plantilla, con el
 *   módulo de la Weekly encendido,
 * - clientes activos con su responsable y su satisfacción (ClientPolicy::viewAny),
 * - equipo: la plantilla que escribe la weekly (como /equipo),
 * - tareas abiertas que puede ver (Task::visibleTo), sin las que ha archivado él,
 * - estado de proyectos en horas, sin importes (D-151), con el módulo encendido,
 * - horas registradas de las últimas 4 semanas solo de las personas cuyas horas puede ver
 *   (User::canSeeHoursOf: las suyas, las de su equipo o todas si es admin, D-021),
 * - importes de venta (bolsas y proyectos) solo con view-financials. Los costes de las personas y los
 *   resúmenes con IA de una persona (D-147) no entran nunca.
 * Un colaborador externo no llega aquí (use-weeklies); aun así, todo se filtra por quien pregunta.
 */
final class AssistantContext
{
    public const int WEEKS = 6;

    public const int SUBMISSIONS = 20;

    public const int TASKS = 40;

    public const int PROJECT_ROWS = 80;

    public const int HOUR_ROWS = 60;

    public const int HOUR_DAYS = 28;

    public const int AMOUNT_ROWS = 40;

    public function __construct(private readonly ProjectStatusBoard $board) {}

    /**
     * Qué secciones entran para esta persona (también lo enseña la página).
     *
     * @return array{weeklies: bool, clients: bool, project_status: bool, hours: 'own'|'team'|'all', financials: bool}
     */
    public static function scope(User $user): array
    {
        return [
            'weeklies' => AppModules::visibleTo($user, AppModule::Weeklies),
            'clients' => Gate::forUser($user)->allows('viewAny', Client::class),
            'project_status' => AppModules::visibleTo($user, AppModule::ProjectStatus) && ! $user->isCollaborator(),
            'hours' => $user->isAdmin() ? 'all' : ($user->isDepartmentManager() && $user->managedDepartmentIds() !== [] ? 'team' : 'own'),
            'financials' => Gate::forUser($user)->allows('view-financials'),
        ];
    }

    /**
     * @return array{asker: string, scope: array<string, mixed>, weeks: list<array<string, mixed>>, report: array<string, mixed>|null, clients: list<array<string, mixed>>, team: list<array<string, mixed>>, submissions: list<array<string, mixed>>, tasks: list<array<string, mixed>>, projects: list<array<string, mixed>>, hours: list<array<string, mixed>>, amounts: list<array<string, mixed>>|null}
     */
    public function for(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $scope = self::scope($user);

        return [
            'asker' => $user->name,
            'scope' => $scope,
            'weeks' => $scope['weeklies'] ? $this->weeks() : [],
            'report' => $scope['weeklies'] ? $this->lastReport() : null,
            'clients' => $scope['clients'] ? $this->clients() : [],
            'team' => $this->team(),
            'submissions' => $scope['weeklies'] ? $this->submissions() : [],
            'tasks' => $this->tasks($user),
            'projects' => $scope['project_status'] ? $this->projects($today) : [],
            'hours' => $this->hours($user, $today),
            'amounts' => $scope['financials'] ? $this->amounts() : null,
        ];
    }

    /**
     * @return list<array{label: string, start: string, end: string, status: string, has_report: bool}>
     */
    private function weeks(): array
    {
        return array_values(WeeklyCycle::query()->orderByDesc('start_date')->orderByDesc('id')->limit(self::WEEKS)
            ->get(['id', 'label', 'start_date', 'end_date', 'status', 'report'])
            ->map(fn (WeeklyCycle $cycle): array => [
                'label' => $cycle->label,
                'start' => $cycle->start_date->toDateString(),
                'end' => $cycle->end_date->toDateString(),
                'status' => $cycle->status->label(),
                'has_report' => $cycle->report !== null,
            ])->all());
    }

    /**
     * El último informe cerrado: resumen global y estado de cada cliente.
     *
     * @return array{label: string, global: string, clients: list<array{name: string, status: string, summary: string}>}|null
     */
    private function lastReport(): ?array
    {
        $cycle = WeeklyCycle::query()->closed()->whereNotNull('report')->orderByDesc('end_date')->orderByDesc('id')->first();
        $report = $cycle?->reportData();

        if ($cycle === null || $report === null) {
            return null;
        }

        $clients = [];
        foreach ($report->clientUpdates as $update) {
            $clients[] = ['name' => $update->clientName, 'status' => $update->status->label(), 'summary' => $update->executiveSummary];
        }

        return ['label' => $cycle->label, 'global' => $report->globalSummary, 'clients' => $clients];
    }

    /**
     * @return list<array{name: string, owner: string|null, satisfaction: int}>
     */
    private function clients(): array
    {
        return array_values(Client::query()->active()
            ->with(['projects' => fn ($query) => $query->select(['id', 'client_id', 'owner_user_id', 'status']), 'projects.owner:id,name', 'owner:id,name,is_active'])
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id', 'satisfaction_score'])
            ->map(fn (Client $client): array => [
                'name' => $client->name,
                'owner' => ClientInsights::ownerOf($client, $client->projects)?->name,
                'satisfaction' => $client->satisfaction_score,
            ])->all());
    }

    /**
     * @return list<array{name: string, job_title: string|null, department: string|null}>
     */
    private function team(): array
    {
        return array_values(User::query()->active()
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', User::WEEKLY_ROLES))
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'job_title', 'department_id'])
            ->map(fn (User $person): array => ['name' => $person->name, 'job_title' => $person->job_title, 'department' => $person->department?->name])
            ->all());
    }

    /**
     * Los reportes ENVIADOS más recientes (los ve toda la plantilla); nunca un borrador.
     *
     * @return list<array{author: string, week: string, general: string|null, entries: list<array{client: string, text: string}>}>
     */
    private function submissions(): array
    {
        return array_values(WeeklySubmission::query()->submitted()
            ->with(['user:id,name', 'cycle:id,label', 'entries' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'entries.client' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(self::SUBMISSIONS)
            ->get()
            ->map(function (WeeklySubmission $submission): array {
                $general = null;
                $entries = [];

                foreach ($submission->entries as $entry) {
                    if ($entry->client_id === null) {
                        $general = $entry->body;

                        continue;
                    }

                    $client = $entry->client;
                    $entries[] = ['client' => $client instanceof Client ? $client->name : 'Cliente', 'text' => $entry->body];
                }

                return ['author' => $submission->user->name, 'week' => $submission->cycle->label, 'general' => $general, 'entries' => $entries];
            })->all());
    }

    /**
     * Tareas abiertas que puede ver, primero las suyas y después por entrega.
     *
     * @return list<array{status: string, title: string, assignee: string|null, client: string|null, project: string, priority: string, due: string|null}>
     */
    private function tasks(User $user): array
    {
        $archived = TaskArchive::query()->where('user_id', $user->id)->select('task_id');

        return array_values(Task::query()
            ->select('tasks.*')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->visibleTo($user)
            ->open()
            ->whereNotIn('tasks.id', $archived)
            ->with(['status:id,name', 'assignee:id,name', 'project:id,code,client_id', 'project.client:id,name'])
            ->orderByRaw('CASE WHEN tasks.assignee_user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tasks.due_date')
            ->orderByDesc('tasks.id')
            ->limit(self::TASKS)
            ->get()
            ->map(fn (Task $task): array => [
                'status' => $task->status->name,
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'client' => $task->project->client?->name,
                'project' => $task->project->code,
                'priority' => $task->priority->label(),
                'due' => $task->due_date?->format('d/m/Y'),
            ])->all());
    }

    /**
     * Estado de proyectos (ProjectStatusBoard), en minutos y sin importes.
     *
     * @return list<array<string, mixed>>
     */
    private function projects(CarbonImmutable $today): array
    {
        $rows = [];

        foreach ($this->board->build($today) as $group) {
            foreach ($group['projects'] as $project) {
                $rows[] = [...$project, 'client' => $group['client']['name'], 'tag' => ProjectKindCode::tagLabel(ProjectKindCode::tag((string) $project['kind_code']))];

                if (count($rows) >= self::PROJECT_ROWS) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Horas de las últimas HOUR_DAYS días, por persona y proyecto, solo de quien puede ver (D-021).
     *
     * @return list<array{person: string, project: string, client: string|null, minutes: int}>
     */
    private function hours(User $user, CarbonImmutable $today): array
    {
        $query = DB::table('time_entries')
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->leftJoin('clients', 'clients.id', '=', 'projects.client_id')
            ->where('time_entries.date', '>=', $today->subDays(self::HOUR_DAYS - 1)->toDateString())
            ->where('time_entries.date', '<', $today->addDay()->toDateString())
            ->groupBy('users.name', 'projects.code', 'clients.name')
            ->selectRaw('users.name as person, projects.code as project, clients.name as client, SUM(time_entries.minutes) as minutes')
            ->orderBy('users.name')
            ->orderByDesc('minutes')
            ->limit(self::HOUR_ROWS);

        if (! $user->isAdmin()) {
            $departments = $user->isDepartmentManager() ? $user->managedDepartmentIds() : [];
            $query->where(fn ($visible) => $visible->where('time_entries.user_id', $user->id)
                ->when($departments !== [], fn ($team) => $team->orWhereIn('users.department_id', $departments)));
        }

        if ($user->isCollaborator()) {
            $query->whereIn('time_entries.project_id', $user->visibleProjectIds() ?? []);
        }

        return array_values($query->get()->map(fn (object $row): array => [
            'person' => (string) $row->person,
            'project' => (string) $row->project,
            'client' => $row->client === null ? null : (string) $row->client,
            'minutes' => (int) $row->minutes,
        ])->all());
    }

    /**
     * Importes de venta (solo con view-financials): bolsas abiertas y proyectos con precio o tarifa.
     *
     * @return list<array{label: string, amount: string|null, rate: string|null}>
     */
    private function amounts(): array
    {
        $rows = [];

        $banks = HourBank::query()
            ->whereIn('status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value])
            ->where(fn ($query) => $query->whereNotNull('price_amount')->orWhereNotNull('hourly_rate'))
            ->with(['project:id,code,client_id', 'project.client:id,name'])
            ->orderBy('project_id')
            ->limit(self::AMOUNT_ROWS)
            ->get();

        foreach ($banks as $bank) {
            $client = $bank->project->client;
            $rows[] = ['label' => ($client instanceof Client ? $client->name : 'Interno').' · '.$bank->project->code.' · '.$bank->name, 'amount' => $bank->price_amount, 'rate' => $bank->hourly_rate];
        }

        $projects = Project::query()
            ->notArchived()
            ->where(fn ($query) => $query->whereNotNull('fixed_price_amount')->orWhereNotNull('hourly_rate'))
            ->with('client:id,name')
            ->orderBy('code')
            ->limit(max(0, self::AMOUNT_ROWS - count($rows)))
            ->get(['id', 'code', 'name', 'client_id', 'fixed_price_amount', 'hourly_rate']);

        foreach ($projects as $project) {
            $client = $project->client;
            $rows[] = ['label' => ($client instanceof Client ? $client->name : 'Interno').' · '.$project->code, 'amount' => $project->fixed_price_amount, 'rate' => $project->hourly_rate];
        }

        return $rows;
    }
}
