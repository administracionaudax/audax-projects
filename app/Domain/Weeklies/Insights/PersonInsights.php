<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\ProjectStatus\ProjectKindCode;
use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Domain\Weeklies\WeeklyCalendar;
use App\Domain\Weeklies\WeeklyTeamStatus;
use App\Domain\Weeklies\WeeklyTiming;
use App\Enums\WeeklyPersonStatus;
use App\Http\Resources\UserSummaryResource;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Equipo» de la Weekly con las personas de Audax (F-134 a F-145, D-194):
 * - la lista: cada persona de plantilla activa con su departamento, puesto, rol, el estado de su
 *   weekly en la semana activa (F-136) y si hoy está ausente (el tipo solo para quien puede verlo,
 *   D-088), más los clientes de sus proyectos para el filtro por cliente,
 * - la ficha: sus clientes (los que lidera y en los que colabora, F-145), su último reporte por
 *   cliente y su historial por semanas (F-143), y sus hábitos de envío (F-142),
 * - y lo que necesitan los prompts de desempeño (F-144) y de actividad por cliente (F-145).
 *
 * Un cliente «lo lidera» quien gestiona alguno de sus proyectos abiertos y «colabora» quien es
 * miembro sin gestionarlo (D-156). Consultas acotadas: no crecen con la plantilla ni con el histórico.
 */
final class PersonInsights
{
    /** Semanas del historial de la ficha (un año). */
    public const int HISTORY_WEEKS = 52;

    public function __construct(
        private readonly WeeklyTeamStatus $teamStatus,
        private readonly WeeklyProjectStatus $projectStatus,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * La lista del equipo.
     *
     * @return array{members: list<array<string, mixed>>, cycle: array<string, mixed>|null}
     */
    public function directory(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $users = User::query()
            ->where('is_active', true)
            ->role(User::WEEKLY_ROLES)
            ->with(['department:id,name', 'roles:id,name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $cycle = WeeklyCycle::query()->active()->first();
        $statuses = [];

        if ($cycle !== null) {
            foreach ($this->teamStatus->for($cycle, $now)['members'] as $member) {
                $statuses[(int) $member['user']['id']] = $member;
            }
        }

        $absences = $this->absencesToday($users->modelKeys());
        $clients = $this->clientIdsByUser($users->modelKeys());
        $rows = [];

        foreach ($users as $user) {
            $status = $statuses[$user->id] ?? null;

            $rows[] = [
                'user' => (new UserSummaryResource($user))->resolve(),
                'email' => $user->email,
                'job_title' => $user->job_title,
                'department' => $user->department === null ? null : ['id' => $user->department->id, 'name' => $user->department->name],
                'role' => $user->getRoleNames()->first(),
                'report_status' => $cycle === null ? null : ($status['status'] ?? WeeklyPersonStatus::NotRequired->value),
                'submitted_at' => $status['submitted_at'] ?? null,
                'absence' => self::absenceFor($absences[$user->id] ?? null, $viewer, $user),
                'client_ids' => $clients[$user->id] ?? [],
            ];
        }

        return ['members' => $rows, 'cycle' => $cycle === null ? null : ClientInsights::cycleRef($cycle)];
    }

    /**
     * La ficha de una persona (sin los resúmenes con IA, que van aparte y con permiso).
     *
     * @return array<string, mixed>
     */
    public function profile(User $person, User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cycles = WeeklyCycle::query()
            ->orderByDesc('start_date')
            ->limit(self::HISTORY_WEEKS)
            ->get(['id', 'number', 'label', 'start_date', 'end_date', 'deadline_date', 'status'])
            ->keyBy('id');

        $submissions = WeeklySubmission::query()
            ->where('user_id', $person->id)
            ->whereNotNull('submitted_at')
            ->whereIn('weekly_cycle_id', $cycles->keys())
            ->with(['entries' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'entries.client:id,name,icon', 'entries.project:id,code'])
            ->get()
            ->keyBy('weekly_cycle_id');

        $weeks = [];
        $lastByClient = [];

        foreach ($cycles as $cycle) {
            $submission = $submissions->get($cycle->id);

            if ($submission === null || $submission->submitted_at === null) {
                continue;
            }

            $entries = $submission->entries->map(fn ($entry): array => [
                'id' => $entry->id,
                'client' => $entry->client === null ? null : ['id' => $entry->client->id, 'name' => $entry->client->name, 'icon' => $entry->client->icon],
                'project' => $entry->project === null ? null : ['id' => $entry->project->id, 'code' => $entry->project->code],
                'body' => $entry->body,
            ])->values()->all();

            $weeks[] = [
                'cycle' => ClientInsights::cycleRef($cycle),
                'status' => ($this->timing->isOnTime($submission->submitted_at, $cycle->deadline_date->toDateString())
                    ? WeeklyPersonStatus::Submitted
                    : WeeklyPersonStatus::SubmittedLate)->value,
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'entries' => $entries,
            ];

            foreach ($entries as $entry) {
                $key = $entry['client']['id'] ?? 'general';
                $lastByClient[$key] ??= [
                    'client' => $entry['client'],
                    'cycle' => ClientInsights::cycleRef($cycle),
                    'submitted_at' => $submission->submitted_at->toIso8601String(),
                    'body' => $entry['body'],
                ];
            }
        }

        $absence = $this->absencesToday([$person->id])[$person->id] ?? null;
        $submittedAt = [];

        foreach ($submissions as $submission) {
            if ($submission->submitted_at !== null) {
                $submittedAt[] = $submission->submitted_at;
            }
        }

        return [
            'person' => [
                ...(new UserSummaryResource($person))->resolve(),
                'email' => $person->email,
                'job_title' => $person->job_title,
                'department' => $person->department === null ? null : ['id' => $person->department->id, 'name' => $person->department->name],
                'role' => $person->getRoleNames()->first(),
            ],
            'absence' => self::absenceFor($absence, $viewer, $person),
            'habits' => self::habits($submittedAt),
            'clients' => $this->clients($person),
            'last_reports' => array_values($lastByClient),
            'weeks' => $weeks,
        ];
    }

    /**
     * Hábitos de envío (calculateSubmissionStats de TeamView.tsx, en la hora de Madrid): la hora
     * media, el momento del día (mañana antes de las 12, tarde hasta las 18 y noche después), el
     * reparto por día (viernes, sábado, domingo y el resto) y el día más habitual (a igualdad, el
     * primero de ese orden).
     *
     * @param  list<\DateTimeInterface>  $submittedAt
     * @return array{total: int, average_time: string, time_of_day: string, most_common_day: string, distribution: array{friday: int, saturday: int, sunday: int, other: int}}|null
     */
    public static function habits(array $submittedAt): ?array
    {
        if ($submittedAt === []) {
            return null;
        }

        $hours = 0.0;
        $distribution = ['friday' => 0, 'saturday' => 0, 'sunday' => 0, 'other' => 0];

        foreach ($submittedAt as $instant) {
            $local = CarbonImmutable::instance($instant)->setTimezone(WeeklyCalendar::TIMEZONE);
            $hours += $local->hour + $local->minute / 60;
            $day = match ($local->dayOfWeek) {
                5 => 'friday',
                6 => 'saturday',
                0 => 'sunday',
                default => 'other',
            };
            $distribution[$day]++;
        }

        $average = $hours / count($submittedAt);
        $hour = (int) floor($average);
        $minute = (int) floor(($average - $hour) * 60);

        $mostCommon = 'friday';
        $max = -1;

        foreach ($distribution as $day => $count) {
            if ($count > $max) {
                $max = $count;
                $mostCommon = $day;
            }
        }

        return [
            'total' => count($submittedAt),
            'average_time' => sprintf('%02d:%02d', $hour, $minute),
            'time_of_day' => $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'),
            'most_common_day' => $mostCommon,
            'distribution' => $distribution,
        ];
    }

    /**
     * Los clientes activos de la persona: los que lidera (gestiona algún proyecto abierto) y en los
     * que colabora (es miembro), con las insignias de sus proyectos (F-120).
     *
     * @return array{owned: list<array<string, mixed>>, member: list<array<string, mixed>>}
     */
    public function clients(User $person): array
    {
        $projects = Project::query()
            ->whereNotNull('client_id')
            ->whereIn('status', array_map(fn ($status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES))
            ->whereHas('client', fn (Builder $client) => $client->where('is_active', true))
            ->where(fn (Builder $query) => $query->where('owner_user_id', $person->id)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($person->id)))
            ->with(['client:id,name,icon', 'members' => fn ($members) => $members->whereKey($person->id)])
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name', 'billing_type', 'description', 'owner_user_id']);

        $groups = ['owned' => [], 'member' => []];

        foreach ($projects->groupBy('client_id') as $clientProjects) {
            /** @var Collection<int, Project> $clientProjects */
            $client = $clientProjects->first()?->client;

            if (! $client instanceof Client) {
                continue;
            }

            $leads = $clientProjects->contains(fn (Project $project): bool => $project->owner_user_id === $person->id
                || (bool) $project->members->first()?->membership?->is_manager);

            $groups[$leads ? 'owned' : 'member'][] = [
                'id' => $client->id,
                'name' => $client->name,
                'icon' => $client->icon,
                'badges' => ProjectKindCode::badges($clientProjects->map(fn (Project $project): string => ProjectKindCode::for($project->code, $this->projectStatus->kind($project)))->all()),
                'projects' => $clientProjects->map(fn (Project $project): array => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name])->values()->all(),
            ];
        }

        foreach ($groups as $key => $rows) {
            usort($rows, fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
            $groups[$key] = $rows;
        }

        return $groups;
    }

    // --- Para la IA -----------------------------------------------------------------------------

    /**
     * El historial del prompt de desempeño: sus 8 últimos envíos, con el apunte «General / Interno»
     * como reporte general y los de cada cliente.
     *
     * @return list<array{weekLabel: string, submittedDate: string, general: string|null, entries: list<array{clientName: string, text: string}>}>
     */
    public function performancePayload(User $person): array
    {
        $submissions = WeeklySubmission::query()
            ->where('user_id', $person->id)
            ->whereNotNull('submitted_at')
            ->with(['cycle:id,label', 'entries' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'entries.client:id,name'])
            ->orderByDesc('submitted_at')
            ->limit(InsightPrompts::PERFORMANCE_MAX_SUBMISSIONS)
            ->get();
        $result = [];

        foreach ($submissions as $submission) {
            $entries = [];
            $general = null;

            foreach ($submission->entries as $entry) {
                if ($entry->client === null) {
                    $general ??= $entry->body;

                    continue;
                }

                $entries[] = ['clientName' => $entry->client->name, 'text' => $entry->body];
            }

            $result[] = [
                'weekLabel' => $submission->cycle->label,
                'submittedDate' => CarbonImmutable::instance($submission->submitted_at ?? CarbonImmutable::now())->setTimezone(WeeklyCalendar::TIMEZONE)->format('j/n/Y'),
                'general' => $general,
                'entries' => $entries,
            ];
        }

        return $result;
    }

    /**
     * El INPUT_JSON de analyze-user-client-activity: por cliente que lidera o en el que colabora, sus
     * 6 reportes más recientes (de sus 80 últimos envíos), recortados a 320 caracteres.
     *
     * @return list<array{clientId: string, clientName: string, role: string, reports: list<array{weekLabel: string, submittedAt: string, text: string}>}>
     */
    public function clientActivityPayload(User $person): array
    {
        $groups = $this->clients($person);
        $clients = [];

        foreach (['owned' => 'owner', 'member' => 'collaborator'] as $group => $role) {
            foreach ($groups[$group] as $client) {
                $clients[] = ['id' => (int) $client['id'], 'name' => (string) $client['name'], 'role' => $role];
            }
        }

        usort($clients, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        if ($clients === []) {
            return [];
        }

        $ids = array_column($clients, 'id');
        $reports = [];

        $submissions = WeeklySubmission::query()
            ->where('user_id', $person->id)
            ->whereNotNull('submitted_at')
            ->with(['cycle:id,label', 'entries' => fn ($query) => $query->whereIn('client_id', $ids)])
            ->orderByDesc('submitted_at')
            ->limit(InsightPrompts::PERSON_ACTIVITY_MAX_SUBMISSIONS)
            ->get();

        foreach ($submissions as $submission) {
            foreach ($submission->entries as $entry) {
                $text = InsightPrompts::truncateDots($entry->body, InsightPrompts::PERSON_ACTIVITY_MAX_ENTRY_LENGTH);

                if ($text === '' || $entry->client_id === null) {
                    continue;
                }

                $reports[$entry->client_id][] = [
                    'weekLabel' => $submission->cycle->label ?: 'Semana',
                    'submittedAt' => $submission->submitted_at?->toIso8601String() ?? '',
                    'text' => $text,
                ];
            }
        }

        return array_map(fn (array $client): array => [
            'clientId' => (string) $client['id'],
            'clientName' => $client['name'],
            'role' => $client['role'],
            'reports' => array_slice($reports[$client['id']] ?? [], 0, InsightPrompts::PERSON_ACTIVITY_MAX_REPORTS),
        ], $clients);
    }

    // --- Piezas ---------------------------------------------------------------------------------

    /**
     * Ausencias aprobadas de día completo que cubren hoy, por persona.
     *
     * @param  list<int>|array<int, int>  $userIds
     * @return array<int, Absence>
     */
    private function absencesToday(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $today = LocalTime::todayString();

        return Absence::query()
            ->approved()
            ->whereIn('user_id', array_values($userIds))
            ->whereNull('partial_minutes')
            ->overlapping($today, $today)
            ->orderBy('start_date')
            ->get(['id', 'user_id', 'type', 'start_date', 'end_date'])
            ->keyBy('user_id')
            ->all();
    }

    /**
     * La ausencia de hoy para quien mira: «Ausente» hasta tal día y, solo si puede saberlo, el tipo
     * (una baja es un dato de salud, D-088).
     *
     * @return array{type: string|null, until: string}|null
     */
    private static function absenceFor(?Absence $absence, User $viewer, User $person): ?array
    {
        if ($absence === null) {
            return null;
        }

        return [
            'type' => $viewer->canSeeAbsencesOf($person) ? $absence->type->value : null,
            'until' => $absence->end_date->toDateString(),
        ];
    }

    /**
     * Clientes de los proyectos abiertos de cada persona (gestora o miembro), para el filtro por
     * cliente de la lista (F-134).
     *
     * @param  list<int>|array<int, int>  $userIds
     * @return array<int, list<int>>
     */
    private function clientIdsByUser(array $userIds): array
    {
        $open = array_map(fn ($status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES);
        $result = [];

        $members = DB::table('project_members')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->whereIn('project_members.user_id', array_values($userIds))
            ->whereIn('projects.status', $open)
            ->whereNotNull('projects.client_id')
            ->whereNull('projects.deleted_at')
            ->distinct()
            ->get(['project_members.user_id', 'projects.client_id']);

        $owners = DB::table('projects')
            ->whereIn('owner_user_id', array_values($userIds))
            ->whereIn('status', $open)
            ->whereNotNull('client_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->get(['owner_user_id as user_id', 'client_id']);

        foreach ([...$members, ...$owners] as $row) {
            $result[(int) $row->user_id][(int) $row->client_id] = (int) $row->client_id;
        }

        return array_map(fn (array $ids): array => array_values($ids), $result);
    }
}
