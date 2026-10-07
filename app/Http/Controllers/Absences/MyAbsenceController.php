<?php

namespace App\Http\Controllers\Absences;

use App\Domain\Absences\AbsenceDays;
use App\Domain\Absences\AbsenceDocuments;
use App\Domain\Absences\AbsencePresenter;
use App\Domain\Absences\AbsenceRules;
use App\Domain\Absences\AbsenceService;
use App\Domain\Absences\AbsenceText;
use App\Domain\Absences\LeaveBalances;
use App\Domain\Absences\LeaveCatalog;
use App\Domain\Absences\LeaveLedger;
use App\Domain\Absences\LeaveMode;
use App\Domain\Absences\LeavePresenter;
use App\Enums\AbsenceStatus;
use App\Http\Requests\Absences\StoreAbsenceRequest;
use App\Models\Absence;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mis ausencias» (/ausencias, SPEC §5.1, D-049): las de quien mira (pendientes, próximas y las
 * del último año), solicitar una nueva y cancelar. Cancelar (o anular, si es quien aprueba) también
 * se hace desde «Ausencias del equipo» con la misma ruta.
 *
 * Fase 11, R3 (con el módulo `people` visible, LeaveMode): la prop `leave` con el catálogo, los
 * saldos del año y del siguiente y los límites de los justificantes, y en cada ausencia su tipo,
 * coste, justificantes, avisos y cancelación (LeavePresenter). Con el módulo apagado, `leave` es
 * null y la página es la de la Fase 3.
 */
class MyAbsenceController extends AbsencesController
{
    /** Ausencias que se listan como mucho. */
    public const int LIMIT = 200;

    public function __construct(
        private readonly AbsenceService $absences,
        private readonly AbsenceDays $days,
        private readonly LeavePresenter $leave,
        private readonly LeaveBalances $balances,
        private readonly LeaveLedger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Absence::class);

        /** @var User $user */
        $user = $request->user();
        $today = CarbonImmutable::parse(LocalTime::todayString());

        $absences = Absence::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $query) => $query
                ->where('end_date', '>=', $today->subYear()->toDateString())
                ->orWhere('status', AbsenceStatus::Requested->value))
            ->with(['approver:id,name', 'leaveType'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $absences->each(fn (Absence $absence) => $absence->setRelation('user', $user));
        $days = $this->days->count($absences);
        $leave = $this->leave->forAbsences($absences, $user);

        return Inertia::render('absences/index', [
            'absences' => $absences->map(fn (Absence $absence): array => AbsencePresenter::row($absence, $user, $days, leave: $leave))->values()->all(),
            'types' => $this->types(),
            'today' => $today->toDateString(),
            'limits' => [
                'from' => $today->subYears(AbsenceRules::YEARS_AROUND)->toDateString(),
                'to' => $today->addYears(AbsenceRules::YEARS_AROUND)->toDateString(),
            ],
            'self_approves' => $this->absences->selfApproves($user),
            'can' => ['team' => Gate::allows('viewTeam', Absence::class)],
            'leave' => LeaveMode::on($user) ? $this->leaveProps($user, (int) $today->format('Y')) : null,
        ]);
    }

    /**
     * Lo que R3 añade a «Mis ausencias»: el catálogo que se puede pedir, los saldos del año y del
     * siguiente (se asignan aquí si aún no lo ha hecho la tarea diaria) y los límites de los
     * justificantes.
     *
     * @return array<string, mixed>
     */
    private function leaveProps(User $user, int $year): array
    {
        foreach ([$year, $year + 1] as $item) {
            $this->ledger->syncYear($item, $user);
        }

        return [
            'types' => LeaveCatalog::active()->map(fn (LeaveType $type): array => LeavePresenter::type($type))->values()->all(),
            'year' => $year,
            'balances' => LeavePresenter::balances($this->balances->forUser($user, $year)),
            'next_balances' => LeavePresenter::balances($this->balances->forUser($user, $year + 1)),
            'documents' => [
                'max_kilobytes' => AbsenceDocuments::MAX_KILOBYTES,
                'max_per_absence' => AbsenceDocuments::MAX_PER_ABSENCE,
                'accept' => implode(',', array_map(fn (string $extension): string => '.'.$extension, AbsenceDocuments::MIMES)),
            ],
        ];
    }

    /**
     * POST /ausencias: solicita una ausencia propia (se aprueba sola si es responsable o admin).
     */
    public function store(StoreAbsenceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $absence = $this->absences->request($user, $request->absenceData());

        $this->toast(AbsenceText::get(
            $absence->status === AbsenceStatus::Approved ? 'absences.flash.auto_approved' : 'absences.flash.requested',
            [
                'type' => AbsenceText::typeName($absence->leaveType, $absence->type),
                'period' => AbsenceText::period($absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->partial_minutes),
            ],
        ));

        return back();
    }

    /**
     * POST /ausencias/{absence}/cancelar: la persona cancela una suya o quien aprueba la anula.
     */
    public function cancel(Request $request, Absence $absence): RedirectResponse
    {
        $this->authorize('cancel', $absence);

        /** @var User $user */
        $user = $request->user();
        $absence = $this->absences->cancel($user, $absence);

        $this->toast($absence->user_id === $user->id
            ? AbsenceText::get('absences.flash.cancelled')
            : AbsenceText::get('absences.flash.annulled', ['name' => $absence->user->name]));

        return back();
    }
}
