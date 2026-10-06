<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Domain\Weeklies\WeeklyClientSubscriptions;
use App\Domain\Weeklies\WeeklyCycleCloser;
use App\Domain\Weeklies\WeeklyCycleOpener;
use App\Domain\Weeklies\WeeklyJobProgress;
use App\Domain\Weeklies\WeeklyOverview;
use App\Domain\Weeklies\WeeklyReportState;
use App\Domain\Weeklies\WeeklyRuleViolation;
use App\Domain\Weeklies\WeeklyStreaks;
use App\Domain\Weeklies\WeeklyTeamStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\UpdateWeeklyDeadlineRequest;
use App\Http\Resources\UserSummaryResource;
use App\Http\Resources\Weeklies\WeeklyCycleDetailResource;
use App\Models\Client;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Semanas de la weekly (F-030 a F-040, F-064 a F-070 y F-089):
 * - /weeklies: pestañas «Resumen» (mi weekly, racha, estado del equipo y, para quien gestiona, la
 *   gestión de la semana) e «Histórico» (semana activa y última cerrada destacadas, y la tabla),
 * - abrir la semana (F-040), ampliar el plazo (F-068) y borrar (F-069) (10.2),
 * - el informe de una semana y el cierre (10.3).
 */
class WeeklyCycleController extends Controller
{
    public const array TABS = ['resumen', 'historico'];

    /** /weeklies?pestana=resumen|historico. */
    public function index(Request $request, WeeklyOverview $overview, MyWeeklyStatus $status, WeeklyStreaks $streaks, WeeklyClientSubscriptions $subscriptions): Response
    {
        Gate::authorize('viewAny', WeeklyCycle::class);

        /** @var User $user */
        $user = $request->user();
        $tab = in_array($request->query('pestana'), self::TABS, true) ? (string) $request->query('pestana') : 'resumen';
        $data = $overview->build();
        $active = $data['active'] === null ? null : WeeklyCycle::query()->whereKey($data['active']['id'])->first();

        return Inertia::render('weeklies/index', [
            'tab' => $tab,
            ...$data,
            'me' => $active === null ? null : $status->for($user, $active),
            'streak' => $streaks->summary($user),
            'my_clients' => $this->myClients($user, $subscriptions),
            'joinable_clients' => Inertia::optional(fn (): array => $subscriptions->joinable($user)),
            'can' => [
                'manage' => Gate::allows('manage-weeklies'),
                'create' => Gate::allows('create', WeeklyCycle::class) && $active === null,
                'extendDeadline' => $active !== null && Gate::allows('extendDeadline', $active),
                'delete' => Gate::allows('manage-weeklies'),
                'exempt' => $active !== null && Gate::allows('manage-weeklies'),
                'remind' => $active !== null && Gate::allows('remind', $active),
                'reminders' => Gate::allows('manage-weeklies'),
            ],
        ]);
    }

    /**
     * /weeklies/{cycle}: el informe de una semana (F-072 a F-091, D-190), como el ReportView de
     * WeeklySync: el informe, el estado del equipo, los reportes originales de cada cliente, el
     * audio, el progreso de lo que se esté generando y lo que puede hacer quien gestiona.
     */
    public function show(Request $request, WeeklyCycle $cycle, WeeklyTeamStatus $team): Response
    {
        Gate::authorize('view', $cycle);

        /** @var User $user */
        $user = $request->user();
        $cycle->load('audioSections');
        $teamStatus = $team->for($cycle);
        $submitted = WeeklySubmission::query()->submitted()->where('weekly_cycle_id', $cycle->id)->count();

        return Inertia::render('weeklies/show', [
            'cycle' => WeeklyCycleDetailResource::make($cycle),
            'team' => $teamStatus,
            'reports' => $this->originalReports($cycle),
            'stale' => WeeklyReportState::isStale($cycle, $submitted),
            'submitted_count' => $submitted,
            'my_client_ids' => $user->projects()->whereNotNull('client_id')->distinct()->pluck('client_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'progress' => [
                'report' => WeeklyJobProgress::detail($cycle->id, WeeklyJobProgress::REPORT),
                'audio' => WeeklyJobProgress::detail($cycle->id, WeeklyJobProgress::AUDIO),
            ],
            'close' => [
                'blockers' => WeeklyReportState::closeBlockers($cycle, WeeklyCycleCloser::hasAudio($cycle)),
                'pending' => $cycle->isActive() ? $teamStatus['counts']['pending'] : 0,
            ],
            'report_request' => (new ReportRequest(ReportKind::Weekly, ['cycle' => $cycle->id], []))->toArray(),
            'can' => [
                'generate' => Gate::allows('generate', $cycle),
                'edit' => Gate::allows('update', $cycle),
                'extendDeadline' => Gate::allows('extendDeadline', $cycle),
                'close' => Gate::allows('close', $cycle),
                'delete' => Gate::allows('delete', $cycle),
            ],
        ]);
    }

    /** «Iniciar la semana» (F-040): abre la que toca si no hay ninguna activa. */
    public function store(WeeklyCycleOpener $opener): RedirectResponse
    {
        Gate::authorize('create', WeeklyCycle::class);

        if (WeeklyCycle::query()->active()->exists()) {
            throw ValidationException::withMessages(['cycle' => (new WeeklyRuleViolation(WeeklyRuleViolation::ALREADY_ACTIVE))->userMessage()]);
        }

        $cycle = $opener->ensureOpen();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.opened', ['label' => $cycle->label])]);

        return to_route('weeklies.index');
    }

    /** Ampliar (o cambiar) el plazo de la semana activa (F-068). */
    public function deadline(UpdateWeeklyDeadlineRequest $request, WeeklyCycle $cycle, WeeklyReminders $reminders): RedirectResponse
    {
        $before = $cycle->deadline_date->toDateString();
        $cycle->forceFill(['deadline_date' => $request->date('deadline_date')?->toDateString()])->save();

        // Plazo cambiado (10.5, D-199): aviso a quien aún debe enviar la weekly.
        /** @var User $user */
        $user = $request->user();
        $notified = $cycle->deadline_date->toDateString() !== $before ? $reminders->notifyDeadline($cycle, $user)->notified : 0;

        Inertia::flash('toast', ['type' => 'success', 'message' => $notified > 0
            ? trans_choice('weeklies.flash.deadline_updated_notified', $notified, ['count' => $notified])
            : __('weeklies.flash.deadline_updated')]);

        return back();
    }

    /**
     * Cerrar la semana (F-035, F-070 y F-089, D-191): con el texto y el audio generados; congela la
     * participación, abre la siguiente y, en segundo plano, la satisfacción y el aviso.
     */
    public function close(Request $request, WeeklyCycle $cycle, WeeklyCycleCloser $closer): RedirectResponse
    {
        Gate::authorize('close', $cycle);

        /** @var User $user */
        $user = $request->user();
        $next = $closer->close($cycle, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.closed', ['label' => $cycle->label, 'next' => $next->label])]);

        return back();
    }

    /**
     * Borrar (F-069, irreversible): se llevan en cascada sus envíos, apuntes, exenciones, dictados,
     * audio y satisfacción, y se borran los MP3 del disco. Si era la más reciente, abre la siguiente.
     */
    public function destroy(WeeklyCycle $cycle, WeeklyCycleOpener $opener): RedirectResponse
    {
        Gate::authorize('delete', $cycle);

        $files = $cycle->audioSections()->get(['disk', 'path'])
            ->map(fn ($section): array => [$section->disk, $section->path])
            ->push([$cycle->audio_disk, $cycle->audio_path])
            ->filter(fn (array $file): bool => $file[0] !== null && $file[1] !== null)
            ->values();

        DB::transaction(fn () => $cycle->delete());

        foreach ($files as [$disk, $path]) {
            Storage::disk((string) $disk)->delete((string) $path);
        }

        $opened = $opener->afterDelete($cycle);

        Inertia::flash('toast', ['type' => 'success', 'message' => $opened === null
            ? __('weeklies.flash.deleted', ['label' => $cycle->label])
            : __('weeklies.flash.deleted_and_opened', ['label' => $cycle->label, 'next' => $opened->label])]);

        return to_route('weeklies.index', ['pestana' => 'historico']);
    }

    /**
     * Los reportes originales de la semana por cliente (F-078, «Ver reportes»): solo los enviados,
     * con su autor y cuándo; «general» = los apuntes sin cliente.
     *
     * @return array<int|string, list<array{author: array<array-key, mixed>, body: string, submitted_at: string|null, project_id: int|null}>>
     */
    private function originalReports(WeeklyCycle $cycle): array
    {
        $reports = [];
        $submissions = WeeklySubmission::query()
            ->submitted()
            ->where('weekly_cycle_id', $cycle->id)
            ->with(['user', 'entries'])
            ->orderBy('submitted_at')
            ->get();

        foreach ($submissions as $submission) {
            foreach ($submission->entries as $entry) {
                if (trim((string) $entry->body) === '') {
                    continue;
                }

                $reports[$entry->client_id ?? 'general'][] = [
                    'author' => UserSummaryResource::make($submission->user)->resolve(),
                    'body' => (string) $entry->body,
                    'submitted_at' => $submission->submitted_at?->toIso8601String(),
                    'project_id' => $entry->project_id,
                ];
            }
        }

        return $reports;
    }

    /**
     * Mis clientes (F-033): los de los proyectos abiertos que gestiono (responsable) y aquellos en los
     * que colaboro: miembro de alguno de sus proyectos o unido en la Weekly (D-221, `subscribed`, que
     * es lo único que se puede dejar desde aquí). Solo clientes activos, con enlace a su ficha.
     *
     * @return array{owned: list<array<string, mixed>>, member: list<array<string, mixed>>}
     */
    private function myClients(User $user, WeeklyClientSubscriptions $subscriptions): array
    {
        $projects = $user->projects()
            ->notArchived()
            ->whereNotNull('client_id')
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->with('client:id,name,icon')
            ->orderBy('code')
            ->get(['projects.id', 'projects.client_id', 'projects.code', 'projects.name', 'projects.owner_user_id']);

        $subscribed = array_flip($subscriptions->clientIds($user));
        $groups = ['owned' => [], 'member' => []];
        $entry = fn (Client $client): array => [
            'id' => $client->id,
            'name' => $client->name,
            'icon' => $client->icon,
            'subscribed' => isset($subscribed[$client->id]),
            'projects' => [],
        ];

        foreach ($projects as $project) {
            $client = $project->client;

            if (! $client instanceof Client) {
                continue;
            }

            $bucket = ($project->membership?->is_manager || $project->owner_user_id === $user->id) ? 'owned' : 'member';
            $groups[$bucket][$client->id] ??= $entry($client);
            $groups[$bucket][$client->id]['projects'][] = ['id' => $project->id, 'code' => $project->code, 'name' => $project->name];
        }

        // Quien gestiona y colabora en el mismo cliente sale solo en «Gestionas».
        $groups['member'] = array_diff_key($groups['member'], $groups['owned']);
        $missing = array_diff_key($subscribed, $groups['owned'], $groups['member']);

        if ($missing !== []) {
            foreach (Client::query()->whereKey(array_keys($missing))->get(['id', 'name', 'icon']) as $client) {
                $groups['member'][$client->id] = $entry($client);
            }
        }

        $sort = function (array $list): array {
            $list = array_values($list);
            usort($list, fn (array $a, array $b): int => strcmp(mb_strtolower((string) $a['name']), mb_strtolower((string) $b['name'])));

            return $list;
        };

        return ['owned' => $sort($groups['owned']), 'member' => $sort($groups['member'])];
    }
}
