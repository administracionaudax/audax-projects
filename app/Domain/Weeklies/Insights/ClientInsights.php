<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\WeeklyClientSubscriptions;
use App\Enums\ProjectStatus;
use App\Http\Resources\UserSummaryResource;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La Weekly en la ficha de cliente (F-128 a F-133, D-194), con los datos de Audax:
 * - Resumen: la última actualización del informe con este cliente y la satisfacción con su tendencia,
 * - Historial: la línea de tiempo por semanas (lo que dijo el informe y los reportes de cada persona),
 * - Equipo: el responsable y los miembros de sus proyectos abiertos con su historial en el cliente,
 * - Satisfacción: la serie de cada cierre (client_satisfaction_snapshots) y las tendencias semanal,
 *   mensual y trimestral (−1, −4 y −13 semanas, como ClientView.tsx).
 * Además reúne lo que necesitan los prompts del resumen y del análisis del equipo.
 *
 * Lo ve la plantilla (D-021): los reportes enviados ya los ve cualquiera que use la Weekly
 * (WeeklySubmissionPolicy::view) y los proyectos son minutos, sin importes. Cada pestaña hace sus
 * consultas, acotadas: no crecen con el histórico ni con el equipo.
 */
final class ClientInsights
{
    /** Semanas que mira el historial (medio año). */
    public const int HISTORY_WEEKS = 26;

    /** Reportes por persona en el historial del equipo. */
    public const int MEMBER_REPORTS = 20;

    public function __construct(
        private readonly ProjectStatusBoard $board,
        private readonly WeeklyClientSubscriptions $subscriptions = new WeeklyClientSubscriptions,
    ) {}

    /**
     * Responsable del cliente: el elegido a mano si sigue activo (D-232) y, si no, el deducido de sus
     * proyectos (mainOwner).
     *
     * @param  iterable<Project>  $projects  con owner cargado
     */
    public static function ownerOf(Client $client, iterable $projects): ?User
    {
        if ($client->owner_user_id !== null) {
            $owner = $client->relationLoaded('owner') ? $client->owner : $client->owner()->first();

            if ($owner !== null && $owner->is_active) {
                return $owner;
            }
        }

        return self::mainOwner($projects);
    }

    /**
     * Responsable del cliente (D.1 del inventario): quien gestiona más proyectos abiertos del cliente;
     * a igualdad, el del proyecto más antiguo. Sin proyectos abiertos, el de cualquiera no archivado.
     *
     * @param  iterable<Project>  $projects  con owner cargado
     */
    public static function mainOwner(iterable $projects): ?User
    {
        /** @var array{open: array<int, array{user: User, count: int, first: int}>, any: array<int, array{user: User, count: int, first: int}>} $pools */
        $pools = ['open' => [], 'any' => []];

        foreach ($projects as $project) {
            if ($project->status === ProjectStatus::Archived) {
                continue;
            }

            $bucket = in_array($project->status, ProjectStatusBoard::OPEN_STATUSES, true) ? 'open' : 'any';
            $row = $pools[$bucket][$project->owner_user_id] ?? ['user' => $project->owner, 'count' => 0, 'first' => $project->id];
            $row['count']++;
            $row['first'] = min($row['first'], $project->id);
            $pools[$bucket][$project->owner_user_id] = $row;
        }

        $open = $pools['open'];
        $any = $pools['any'];
        $pool = $open !== [] ? $open : $any;

        if ($pool === []) {
            return null;
        }

        uasort($pool, fn (array $a, array $b): int => [$b['count'], $a['first']] <=> [$a['count'], $b['first']]);

        return reset($pool)['user'];
    }

    // --- Pestañas -------------------------------------------------------------------------------

    /**
     * Resumen: la última actualización del informe y la satisfacción.
     *
     * @return array{latest: array{cycle: array<string, mixed>, update: array<string, mixed>}|null, satisfaction: array{score: int, trend: int|null}}
     */
    public function summary(Client $client): array
    {
        $latest = null;

        foreach ($this->recentCycles() as $cycle) {
            $update = $this->updateFor($cycle, $client);

            if ($update !== null) {
                $latest = ['cycle' => self::cycleRef($cycle), 'update' => $update->toArray()];
                break;
            }
        }

        return ['latest' => $latest, 'satisfaction' => $this->satisfactionNow($client)];
    }

    /**
     * Historial: por semana, de la más reciente a la más antigua, lo que dijo el informe y los
     * reportes de cada persona sobre el cliente. Solo las semanas con algo.
     *
     * Por páginas de HISTORY_WEEKS semanas (10.9b: ya no se corta en medio año).
     *
     * @return list<array{cycle: array<string, mixed>, update: array<string, mixed>|null, entries: list<array<string, mixed>>}>
     */
    public function history(Client $client, int $page = 1): array
    {
        $cycles = $this->recentCycles($page);
        $entries = $this->entries($client, cycleIds: array_values(array_map(intval(...), $cycles->modelKeys())));
        $users = $this->users(array_values(array_unique(array_column($entries, 'user_id'))));
        $byCycle = [];

        foreach ($entries as $entry) {
            $byCycle[$entry['cycle_id']][] = [
                'id' => $entry['id'],
                'author' => isset($users[$entry['user_id']]) ? (new UserSummaryResource($users[$entry['user_id']]))->resolve() : null,
                'body' => $entry['body'],
                'submitted_at' => $entry['submitted_at']->toIso8601String(),
                'project' => $entry['project_id'] !== null ? ['id' => $entry['project_id'], 'code' => $entry['project_code']] : null,
            ];
        }

        $weeks = [];

        foreach ($cycles as $cycle) {
            $update = $this->updateFor($cycle, $client);
            $rows = $byCycle[$cycle->id] ?? [];

            if ($update === null && $rows === []) {
                continue;
            }

            $weeks[] = ['cycle' => self::cycleRef($cycle), 'update' => $update?->toArray(), 'entries' => $rows];
        }

        return $weeks;
    }

    /**
     * Equipo: el responsable y las personas de sus proyectos abiertos (que escriben la weekly), con
     * sus proyectos en el cliente, su último reporte y su historial en el cliente.
     *
     * @return array{owner_id: int|null, members: list<array<string, mixed>>}
     */
    public function team(Client $client): array
    {
        $projects = $this->openProjects($client);
        $owner = self::ownerOf($client, $projects);
        $members = $this->teamMembers($client, $projects, $owner);
        $entries = $this->entries($client, limit: InsightPrompts::CLIENT_MAX_ENTRY_ROWS);
        $cycles = WeeklyCycle::query()->whereKey(array_values(array_unique(array_column($entries, 'cycle_id'))))->get(['id', 'number', 'label', 'start_date', 'end_date', 'status'])->keyBy('id');
        $reports = [];

        foreach ($entries as $entry) {
            $cycle = $cycles[$entry['cycle_id']] ?? null;

            if ($cycle === null || count($reports[$entry['user_id']] ?? []) >= self::MEMBER_REPORTS) {
                continue;
            }

            $reports[$entry['user_id']][] = [
                'cycle' => self::cycleRef($cycle),
                'body' => $entry['body'],
                'submitted_at' => $entry['submitted_at']->toIso8601String(),
            ];
        }

        $rows = [];

        foreach ($members as $member) {
            $mine = $projects->filter(fn (Project $project): bool => $project->owner_user_id === $member->id
                || $project->members->contains('id', $member->id));

            $rows[] = [
                'user' => (new UserSummaryResource($member))->resolve(),
                'job_title' => $member->job_title,
                'role' => $owner?->id === $member->id ? 'owner' : 'member',
                'projects' => $mine->map(fn (Project $project): array => ['id' => $project->id, 'code' => $project->code])->values()->all(),
                'last_report_at' => $reports[$member->id][0]['submitted_at'] ?? null,
                'reports' => $reports[$member->id] ?? [],
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['role'] === 'owner' ? 0 : 1, mb_strtolower((string) $a['user']['name'])] <=> [$b['role'] === 'owner' ? 0 : 1, mb_strtolower((string) $b['user']['name'])]);

        return ['owner_id' => $owner?->id, 'members' => $rows];
    }

    /**
     * Satisfacción: la puntuación de cada cierre (de la más antigua a la más reciente) y las
     * tendencias semanal, mensual y trimestral.
     *
     * @return array{score: int, points: list<array<string, mixed>>, deltas: array{weekly: int|null, monthly: int|null, quarterly: int|null}}
     */
    public function satisfaction(Client $client): array
    {
        $points = array_values(ClientSatisfactionSnapshot::query()
            ->join('weekly_cycles', 'weekly_cycles.id', '=', 'client_satisfaction_snapshots.weekly_cycle_id')
            ->where('client_satisfaction_snapshots.client_id', $client->id)
            ->orderBy('weekly_cycles.end_date')
            ->get([
                'client_satisfaction_snapshots.weekly_cycle_id',
                'client_satisfaction_snapshots.score',
                'client_satisfaction_snapshots.delta',
                'client_satisfaction_snapshots.reasoning',
                'weekly_cycles.number',
                'weekly_cycles.label',
                'weekly_cycles.end_date',
            ])
            ->map(fn (ClientSatisfactionSnapshot $row): array => [
                'cycle_id' => (int) $row->weekly_cycle_id,
                'number' => (string) $row->getAttribute('number'),
                'label' => (string) $row->getAttribute('label'),
                'end_date' => substr((string) $row->getAttribute('end_date'), 0, 10),
                'score' => max(0, min(100, $row->score)),
                'delta' => $row->delta,
                'reasoning' => $row->reasoning,
            ])
            ->all());

        return ['score' => $client->satisfaction_score, 'points' => $points, 'deltas' => self::deltas(array_column($points, 'score'))];
    }

    /**
     * Tendencias de ClientView.tsx: la última puntuación menos la de 1, 4 y 13 semanas antes.
     *
     * @param  list<int>  $scores  de la más antigua a la más reciente
     * @return array{weekly: int|null, monthly: int|null, quarterly: int|null}
     */
    public static function deltas(array $scores): array
    {
        $last = count($scores) - 1;
        $delta = fn (int $back): ?int => $last >= $back ? $scores[$last] - $scores[$last - $back] : null;

        return ['weekly' => $delta(1), 'monthly' => $delta(4), 'quarterly' => $delta(13)];
    }

    /**
     * Satisfacción actual y tendencia frente al cierre anterior (la lista de clientes, F-096 y F-124).
     *
     * @return array{score: int, trend: int|null}
     */
    public function satisfactionNow(Client $client): array
    {
        $last = ClientSatisfactionSnapshot::query()
            ->where('client_id', $client->id)
            ->latest('id')
            ->first(['score', 'previous_score']);

        return [
            'score' => $client->satisfaction_score,
            'trend' => $last?->previous_score === null ? null : $client->satisfaction_score - $last->previous_score,
        ];
    }

    // --- Para la IA -----------------------------------------------------------------------------

    /**
     * buildRelevantClientWeeks: las 10 últimas semanas con reportes del cliente, con sus reportes
     * (recortados, del más reciente al más antiguo) y la actualización del informe.
     *
     * @return list<ClientSummaryWeek>
     */
    public function summaryWeeks(Client $client): array
    {
        $entries = $this->entries($client, limit: InsightPrompts::CLIENT_MAX_ENTRY_ROWS);

        if ($entries === []) {
            return [];
        }

        $cycles = WeeklyCycle::query()
            ->whereKey(array_values(array_unique(array_column($entries, 'cycle_id'))))
            ->get(['id', 'number', 'label', 'start_date', 'end_date', 'status', 'report'])
            ->keyBy('id');
        $users = $this->users(array_values(array_unique(array_column($entries, 'user_id'))));
        /** @var array<int, ClientSummaryWeek> $weeks */
        $weeks = [];

        foreach ($entries as $entry) {
            $cycle = $cycles[$entry['cycle_id']] ?? null;

            if ($cycle === null) {
                continue;
            }

            $submittedAt = $entry['submitted_at']->toIso8601String();
            $week = $weeks[$cycle->id] ??= new ClientSummaryWeek(
                cycleId: $cycle->id,
                weekLabel: InsightPrompts::truncateEllipsis($cycle->label, 200) ?: 'Semana',
                weekEndDate: $cycle->end_date->toDateString(),
                submittedAt: $submittedAt,
                rawEntries: [],
                update: $this->updateFor($cycle, $client),
            );

            $text = InsightPrompts::truncateEllipsis($entry['body'], InsightPrompts::CLIENT_RAW_ENTRY_MAX_LENGTH);

            if ($text !== '') {
                $week->rawEntries[] = [
                    'authorName' => isset($users[$entry['user_id']]) ? $users[$entry['user_id']]->name : 'Usuario eliminado',
                    'submittedAt' => $submittedAt,
                    'text' => $text,
                ];
            }

            if ($submittedAt > $week->submittedAt) {
                $week->submittedAt = $submittedAt;
            }
        }

        $weeks = array_values($weeks);
        usort($weeks, fn (ClientSummaryWeek $a, ClientSummaryWeek $b): int => strcmp($b->submittedAt ?: $b->weekEndDate, $a->submittedAt ?: $a->weekEndDate));
        $weeks = array_slice($weeks, 0, InsightPrompts::CLIENT_MAX_RELEVANT_WEEKS);

        foreach ($weeks as $week) {
            usort($week->rawEntries, fn (array $a, array $b): int => strcmp($b['submittedAt'], $a['submittedAt']));
        }

        return $weeks;
    }

    /**
     * Datos del prompt del resumen: responsable, equipo y la cartera actual del cliente.
     *
     * @return array{owner: User|null, collaborators: list<string>, projects: list<array<string, mixed>>}
     */
    public function summaryContext(Client $client): array
    {
        $projects = $this->openProjects($client);
        $owner = self::ownerOf($client, $projects);
        $collaborators = array_values($this->teamMembers($client, $projects, $owner)
            ->reject(fn (User $user): bool => $user->id === $owner?->id)
            ->map(fn (User $user): string => $user->name)
            ->all());
        $board = $this->board->build(clientIds: [$client->id]);

        return ['owner' => $owner, 'collaborators' => $collaborators, 'projects' => $board[0]['projects'] ?? []];
    }

    /**
     * El INPUT_JSON de analyze-team-activity: por persona del equipo, sus 6 reportes más recientes del
     * cliente (de los 100 últimos), recortados a 350 caracteres.
     *
     * @return list<array{memberId: string, memberName: string, reports: list<array{week: string, text: string}>}>
     */
    public function teamActivityPayload(Client $client): array
    {
        $members = $this->teamMembers($client, $this->openProjects($client));
        $entries = $this->entries($client, limit: InsightPrompts::TEAM_ACTIVITY_MAX_ENTRY_ROWS);
        $labels = WeeklyCycle::query()->whereKey(array_values(array_unique(array_column($entries, 'cycle_id'))))->pluck('label', 'id');
        $reports = [];

        foreach ($entries as $entry) {
            $reports[$entry['user_id']][] = [
                'week' => (string) ($labels[$entry['cycle_id']] ?? ''),
                'text' => InsightPrompts::truncateRaw($entry['body'], 350),
            ];
        }

        return array_values($members->map(fn (User $member): array => [
            'memberId' => (string) $member->id,
            'memberName' => $member->name,
            'reports' => array_slice($reports[$member->id] ?? [], 0, InsightPrompts::TEAM_ACTIVITY_MAX_REPORTS),
        ])->all());
    }

    // --- Piezas ---------------------------------------------------------------------------------

    /**
     * Proyectos abiertos del cliente con su gestor y sus miembros.
     *
     * @return Collection<int, Project>
     */
    public function openProjects(Client $client): Collection
    {
        return Project::query()
            ->where('client_id', $client->id)
            ->whereIn('status', array_map(fn ($status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES))
            ->with(['owner', 'members:id'])
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name', 'status', 'owner_user_id']);
    }

    /**
     * Las personas del equipo del cliente: gestores y miembros de sus proyectos abiertos y quienes se
     * han unido al cliente en la Weekly (D-221), que escriben la weekly y siguen activas, por nombre.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, User>
     */
    public function teamMembers(Client $client, Collection $projects, ?User $owner = null): Collection
    {
        $ids = $projects->flatMap(fn (Project $project): array => [$project->owner_user_id, ...$project->members->modelKeys()])
            ->merge($this->subscriptions->userIds($client))
            ->merge($owner !== null ? [$owner->id] : [])
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereKey($ids)
            ->where('is_active', true)
            ->role(User::WEEKLY_ROLES)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los apuntes ENVIADOS del cliente, del envío más reciente al más antiguo.
     *
     * @param  list<int>|null  $cycleIds
     * @return list<array{id: int, cycle_id: int, user_id: int, submitted_at: CarbonImmutable, body: string, project_id: int|null, project_code: string|null}>
     */
    public function entries(Client $client, ?int $limit = null, ?array $cycleIds = null): array
    {
        return array_values(DB::table('weekly_entries')
            ->join('weekly_submissions', 'weekly_submissions.id', '=', 'weekly_entries.weekly_submission_id')
            ->leftJoin('projects', 'projects.id', '=', 'weekly_entries.project_id')
            ->where('weekly_entries.client_id', $client->id)
            ->whereNotNull('weekly_submissions.submitted_at')
            ->when($cycleIds !== null, fn ($query) => $query->whereIn('weekly_submissions.weekly_cycle_id', $cycleIds))
            ->orderByDesc('weekly_submissions.submitted_at')
            ->orderByDesc('weekly_entries.id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get([
                'weekly_entries.id',
                'weekly_entries.body',
                'weekly_entries.project_id',
                'projects.code as project_code',
                'weekly_submissions.weekly_cycle_id',
                'weekly_submissions.user_id',
                'weekly_submissions.submitted_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'cycle_id' => (int) $row->weekly_cycle_id,
                'user_id' => (int) $row->user_id,
                'submitted_at' => CarbonImmutable::parse($row->submitted_at, 'UTC'),
                'body' => (string) $row->body,
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'project_code' => $row->project_code === null ? null : (string) $row->project_code,
            ])
            ->all());
    }

    /**
     * Las semanas recientes (con su informe), de la más reciente a la más antigua.
     *
     * @return Collection<int, WeeklyCycle>
     */
    private function recentCycles(int $page = 1): Collection
    {
        return WeeklyCycle::query()
            ->orderByDesc('start_date')
            ->offset((max(1, $page) - 1) * self::HISTORY_WEEKS)
            ->limit(self::HISTORY_WEEKS)
            ->get(['id', 'number', 'label', 'start_date', 'end_date', 'deadline_date', 'status', 'report']);
    }

    /** ¿Hay semanas antes de la página $page del historial? */
    public function hasMoreHistory(int $page): bool
    {
        return WeeklyCycle::query()->offset(max(1, $page) * self::HISTORY_WEEKS)->limit(1)->exists();
    }

    private function updateFor(WeeklyCycle $cycle, Client $client): ?WeeklyClientUpdate
    {
        if ($cycle->report === null) {
            return null;
        }

        $report = WeeklyReport::fromArray($cycle->report);

        return $report->clientUpdate($client->id);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    private function users(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /**
     * Referencia corta a una semana para las pestañas.
     *
     * @return array{id: int, number: string, label: string, start_date: string, end_date: string, status: string}
     */
    public static function cycleRef(WeeklyCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'number' => $cycle->number,
            'label' => $cycle->label,
            'start_date' => $cycle->start_date->toDateString(),
            'end_date' => $cycle->end_date->toDateString(),
            'status' => $cycle->status->value,
        ];
    }
}
