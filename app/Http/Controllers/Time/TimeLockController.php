<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\TimeLockService;
use App\Enums\TimeEntryStatus;
use App\Http\Requests\Time\LockTimeRequest;
use App\Http\Resources\Time\Plain;
use App\Http\Resources\Time\TimeEntryLockResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /horas/bloqueo (gate lock-time: solo admin, D-034): bloquear las horas aprobadas de un cliente o
 * un proyecto en un rango de fechas «al facturar», con vista previa, y deshacer bloqueos.
 */
class TimeLockController extends TimeController
{
    /**
     * Entradas que se listan en la vista previa (el resto se cuenta).
     */
    public const int PREVIEW_LIMIT = 50;

    public function __construct(
        private readonly TimeLockService $locks,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    /**
     * GET /horas/bloqueo/vista-previa?client_id|project_id&date_from&date_to&reference
     */
    public function preview(LockTimeRequest $request): Response
    {
        $client = $request->client();
        $project = $request->project();
        $from = $request->from();
        $to = $request->to();

        $entries = $this->locks->scope($client, $project, $from, $to)
            ->where('time_entries.status', TimeEntryStatus::Approved->value)
            ->with(['user', 'task:id,title,deleted_at', 'project:id,code,name,color,deleted_at'])
            ->orderBy('date')
            ->orderBy('id')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        return $this->page($request, [
            'summary' => $this->locks->preview($client, $project, $from, $to),
            'entries' => $entries->map(fn (TimeEntry $entry): array => Plain::of(new TimeEntryResource($entry)))->all(),
            'limit' => self::PREVIEW_LIMIT,
        ]);
    }

    /**
     * POST /horas/bloqueo
     */
    public function store(LockTimeRequest $request): RedirectResponse
    {
        $this->authorize('lock-time');

        /** @var User $admin */
        $admin = $request->user();
        $lock = $this->locks->lock($admin, $request->client(), $request->project(), $request->from(), $request->to(), $request->reference());

        $this->toast(Messages::choice('time.flash.locked', $lock->entries_count));

        return to_route('time.locks.index');
    }

    /**
     * DELETE /horas/bloqueo/{lock}: deshace el bloqueo (sus entradas vuelven a aprobadas).
     */
    public function destroy(Request $request, TimeEntryLock $lock): RedirectResponse
    {
        $this->authorize('lock-time');

        /** @var User $admin */
        $admin = $request->user();
        $count = $this->locks->unlock($admin, $lock);

        $this->toast(Messages::choice('time.flash.unlocked', $count));

        return to_route('time.locks.index');
    }

    /**
     * @param  array<string, mixed>|null  $preview
     */
    private function page(Request $request, ?array $preview): Response
    {
        $locks = TimeEntryLock::query()
            ->with(['client:id,name,deleted_at', 'project:id,code,name,deleted_at', 'locker'])
            ->latest('id')
            ->limit(50)
            ->get();

        $unlockers = User::query()
            ->whereKey($locks->pluck('unlocked_by')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        return Inertia::render('time/locks', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->all(),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name', 'client_id'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'client_id' => $project->client_id,
                ])->all(),
            'filters' => [
                'client_id' => $request->filled('client_id') ? $request->integer('client_id') : null,
                'project_id' => $request->filled('project_id') ? $request->integer('project_id') : null,
                'date_from' => $request->filled('date_from') ? $request->string('date_from')->toString() : null,
                'date_to' => $request->filled('date_to') ? $request->string('date_to')->toString() : null,
                'reference' => $request->filled('reference') ? $request->string('reference')->toString() : null,
            ],
            'preview' => $preview,
            'locks' => $locks->map(fn (TimeEntryLock $lock): array => [
                ...Plain::of(new TimeEntryLockResource($lock)),
                'unlocked_by' => $lock->unlocked_by !== null ? ['id' => $lock->unlocked_by, 'name' => (string) ($unlockers[$lock->unlocked_by] ?? '')] : null,
            ])->all(),
        ]);
    }
}
