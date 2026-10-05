<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\MyWeeklyClients;
use App\Domain\Weeklies\MyWeeklyHistory;
use App\Domain\Weeklies\MyWeeklyStatus;
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
 * «Mi espacio» (F-041 a F-054): pestañas «Reportes» y «Tareas» (10.6).
 * - Sin ?semana: mis weeklies (F-042), con la semana activa destacada, y mi racha.
 * - Con ?semana={id}: mi weekly de esa semana (F-043 a F-054): una caja por cliente propuesto más
 *   «General / Interno», el catálogo para añadir otros, el autocompletado desde mis tareas y horas,
 *   mi exención y el borrador. Con la semana cerrada, en solo lectura.
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
