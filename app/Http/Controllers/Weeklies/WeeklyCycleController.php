<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyCycleOpener;
use App\Domain\Weeklies\WeeklyOverview;
use App\Domain\Weeklies\WeeklyRuleViolation;
use App\Domain\Weeklies\WeeklyStreaks;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Http\Requests\Weeklies\UpdateWeeklyDeadlineRequest;
use App\Http\Resources\Weeklies\WeeklyCycleDetailResource;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
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
    use PendingDelivery;

    public const array TABS = ['resumen', 'historico'];

    /** /weeklies?pestana=resumen|historico. */
    public function index(Request $request, WeeklyOverview $overview, MyWeeklyStatus $status, WeeklyStreaks $streaks): Response
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
            'my_clients' => $this->myClients($user),
            'joinable_projects' => Inertia::optional(fn (): array => $this->joinableProjects($user)),
            'can' => [
                'manage' => Gate::allows('manage-weeklies'),
                'create' => Gate::allows('create', WeeklyCycle::class) && $active === null,
                'extendDeadline' => $active !== null && Gate::allows('extendDeadline', $active),
                'delete' => Gate::allows('manage-weeklies'),
                'exempt' => $active !== null && Gate::allows('manage-weeklies'),
            ],
        ]);
    }

    /** /weeklies/{cycle}: el informe de una semana (F-072 a F-091, entrega 10.3). */
    public function show(WeeklyCycle $cycle): Response
    {
        Gate::authorize('view', $cycle);

        return Inertia::render('weeklies/show', [
            'cycle' => WeeklyCycleDetailResource::make($cycle->load('audioSections')),
            'can' => [
                'generate' => Gate::allows('generate', $cycle),
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
    public function deadline(UpdateWeeklyDeadlineRequest $request, WeeklyCycle $cycle): RedirectResponse
    {
        $cycle->forceFill(['deadline_date' => $request->date('deadline_date')?->toDateString()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.deadline_updated')]);

        return back();
    }

    /** Cerrar con texto y audio: congela exentos, satisfacción y abre la siguiente (10.3). */
    public function close(WeeklyCycle $cycle): never
    {
        Gate::authorize('close', $cycle);

        $this->pending('10.3');
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
     * Mis clientes (F-033): los de los proyectos que gestiono (responsable) y los de los que soy
     * miembro (colaborador), activos y con enlace a su ficha.
     *
     * @return array{owned: list<array<string, mixed>>, member: list<array<string, mixed>>}
     */
    private function myClients(User $user): array
    {
        $projects = $user->projects()
            ->notArchived()
            ->whereNotNull('client_id')
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->with('client:id,name,icon')
            ->orderBy('code')
            ->get(['projects.id', 'projects.client_id', 'projects.code', 'projects.name', 'projects.owner_user_id']);

        $groups = ['owned' => [], 'member' => []];

        foreach ($projects as $project) {
            $isManager = (bool) $project->membership?->is_manager;
            $bucket = $isManager ? 'owned' : 'member';
            $key = (int) $project->client_id;
            $groups[$bucket][$key] ??= ['id' => $project->client?->id, 'name' => $project->client?->name, 'icon' => $project->client?->icon, 'projects' => []];
            $groups[$bucket][$key]['projects'][] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'can_leave' => ! $isManager && $project->owner_user_id !== $user->id,
            ];
        }

        $sort = function (array $list): array {
            usort($list, fn (array $a, array $b): int => strcmp(mb_strtolower((string) $a['name']), mb_strtolower((string) $b['name'])));

            return $list;
        };

        return ['owned' => $sort($groups['owned']), 'member' => $sort($groups['member'])];
    }

    /**
     * Proyectos abiertos a los que me puedo unir (F-034): los de clientes activos de los que aún no
     * soy miembro. Se piden al abrir el diálogo (prop opcional).
     *
     * @return list<array<string, mixed>>
     */
    private function joinableProjects(User $user): array
    {
        return array_values(Project::query()
            ->notArchived()
            ->whereNotNull('client_id')
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->whereDoesntHave('members', fn ($members) => $members->whereKey($user->id))
            ->with('client:id,name,icon')
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => ['id' => $project->client?->id, 'name' => $project->client?->name, 'icon' => $project->client?->icon],
            ])
            ->all());
    }
}
