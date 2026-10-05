<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\MyWeeklyClients;
use App\Domain\Weeklies\MyWeeklyHistory;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\Tasks\MySpaceTasks;
use App\Domain\Weeklies\Tasks\TaskSuggester;
use App\Domain\Weeklies\WeeklyStreaks;
use App\Http\Controllers\Controller;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Http\Resources\Weeklies\WeeklySubmissionResource;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi espacio» (F-041 a F-063): pestañas «Reportes» y «Tareas».
 * - Sin ?semana: mis weeklies (F-042), con la semana activa destacada, y mi racha.
 * - Con ?semana={id}: mi weekly de esa semana (F-043 a F-054): una caja por cliente propuesto más
 *   «General / Interno», el catálogo para añadir otros, el autocompletado desde mis tareas y horas,
 *   mi exención y el borrador. Con la semana cerrada, en solo lectura.
 * - Tareas (10.6, F-055 a F-063, D-203 y D-204): mis tareas agrupadas por cliente (`my_tasks`), los
 *   proyectos en los que puedo crear (`task_projects`), los estados para marcar hecha
 *   (`task_statuses`) y las tareas sugeridas por IA (`suggestions`, de la weekly `suggestion_source`).
 *   Solo con ?pestana=tareas; con otra pestaña llegan a null sin consultar nada.
 * `cycle` y `submission` son siempre los de la semana activa (contrato 10.1).
 */
class MySpaceController extends Controller
{
    public const array TABS = ['reportes', 'tareas'];

    public function index(
        Request $request,
        MyWeeklyHistory $history,
        MyWeeklyStatus $status,
        MyWeeklyClients $clients,
        WeeklyStreaks $streaks,
        MySpaceTasks $tasks,
        TaskSuggester $suggester,
    ): Response {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $tab = in_array($request->query('pestana'), self::TABS, true) ? (string) $request->query('pestana') : 'reportes';
        $cycle = WeeklyCycle::query()->active()->first();
        $submission = $cycle === null ? null : $this->submission($user, $cycle);

        $selected = null;

        if ($request->filled('semana')) {
            $selected = WeeklyCycle::query()->find($request->integer('semana'));
            abort_if($selected === null, 404);
        }

        return Inertia::render('my-space/index', [
            'tab' => $tab,
            'cycle' => $cycle === null ? null : WeeklyCycleResource::make($cycle),
            'submission' => $submission === null ? null : WeeklySubmissionResource::make($submission),
            'weeks' => $history->for($user),
            'streak' => $streaks->summary($user),
            'editor' => $selected === null ? null : $this->editor($user, $selected, $status, $clients),
            'my_tasks' => $tab === 'tareas' ? fn (): array => $tasks->list($user) : null,
            'task_projects' => $tab === 'tareas' ? fn (): array => $tasks->catalog($user) : null,
            'task_statuses' => $tab === 'tareas' ? fn (): array => $tasks->toggleStatuses() : null,
            'suggestions' => $tab === 'tareas' ? fn (): ?array => TaskSuggester::present($suggester->find($user)) : null,
            'suggestion_source' => $tab === 'tareas' ? function () use ($suggester): ?array {
                $source = $suggester->sourceCycle();

                return $source === null ? null : ['id' => $source->id, 'number' => $source->number, 'label' => $source->label];
            } : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function editor(User $user, WeeklyCycle $cycle, MyWeeklyStatus $status, MyWeeklyClients $clients): array
    {
        $submission = $this->submission($user, $cycle);
        $me = $status->for($user, $cycle);
        $writable = $cycle->isActive() && $me['participates'];
        $exemption = $me['exemption_id'] === null ? null : WeeklyExemption::query()->find($me['exemption_id']);

        return [
            'cycle' => WeeklyCycleResource::make($cycle)->resolve(),
            'me' => $me,
            'submission' => $submission === null ? null : WeeklySubmissionResource::make($submission)->resolve(),
            'clients' => $writable || $submission !== null ? $clients->for($user, $cycle, $submission) : ['proposed' => [], 'catalog' => []],
            'autofill' => $writable ? $clients->autofill($user, $cycle) : [],
            'read_only' => ! $writable || $me['exemption_reason'] !== null,
            'can' => [
                'write' => $writable && $me['exemption_reason'] === null,
                'waive' => $cycle->isActive() && $me['exemption_reason'] !== null && Gate::allows('waive', [WeeklyExemption::class, $cycle]),
                'undo_waiver' => $exemption !== null && $me['waived'] && Gate::allows('delete', $exemption),
            ],
        ];
    }

    private function submission(User $user, WeeklyCycle $cycle): ?WeeklySubmission
    {
        return WeeklySubmission::query()
            ->with('entries.client')
            ->where('weekly_cycle_id', $cycle->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
