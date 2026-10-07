<?php

namespace App\Http\Controllers\People;

use App\Domain\People\OvertimeService;
use App\Domain\People\PeopleAccess;
use App\Domain\People\RegisterStart;
use App\Domain\People\Reports\PeopleReports;
use App\Domain\People\Reports\RegisterFiles;
use App\Domain\People\TimeBalanceLedger;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\TimeBalanceMovement;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Mi registro» (`/personas/registro`, PLAN-FASE-11 §6.1.6 y §11.3; D-346): lo que la ley da a cada
 * persona sobre su registro (art. 34.9 ET; borrador del RD: consulta y copia):
 *
 * - los **cierres mensuales** con su PDF y su huella, y la confirmación o el desacuerdo del pendiente,
 * - la **descarga del registro** de cualquier periodo (hasta un año cada vez) en PDF, Excel o CSV,
 *   con su huella y anotada en la auditoría,
 * - sus **horas extra** del año frente al tope de 80 h y su **saldo de horas** con los plazos.
 *
 * Solo la propia persona: nadie más entra en el «Mi registro» de otro (el responsable y RR. HH.
 * descargan los informes).
 */
class RegisterController extends Controller
{
    use HandlesRegisterFiles;

    public function index(Request $request, OvertimeService $overtime, TimeBalanceLedger $ledger): Response
    {
        /** @var User $user */
        $user = $request->user();
        $year = (int) LocalTime::today()->year;

        $closes = MonthClose::query()
            ->with('reopener:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('month')
            ->orderByDesc('version')
            ->limit(60)
            ->get()
            ->map(fn (MonthClose $close): array => self::close($close, $user))
            ->all();

        $decisions = OvertimeDecision::query()
            ->effective()
            ->with('decider:id,name')
            ->where('user_id', $user->id)
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->orderByDesc('date')
            ->get()
            ->map(fn (OvertimeDecision $decision): array => self::decision($decision))
            ->all();

        return Inertia::render('people/register', [
            'subject' => ['id' => $user->id, 'name' => $user->name],
            'today' => LocalTime::todayString(),
            'register_start' => RegisterStart::date(),
            'period' => ['from' => LocalTime::today()->startOfMonth()->toDateString(), 'to' => LocalTime::todayString()],
            'max_days' => $this->maxPeriodDays,
            'closes' => $closes,
            'overtime' => $overtime->yearSummary($user, $year),
            'decisions' => $decisions,
            'balance' => [
                'minutes' => $ledger->balance($user->id),
                'pending' => $ledger->pendingCompensation($user->id),
                'movements' => array_map(fn (TimeBalanceMovement $movement): array => self::movement($movement), $ledger->movements($user->id, 50)),
            ],
            'subject_to_register' => PeopleAccess::subject($user),
        ]);
    }

    /** GET /personas/registro/descargar?desde=&hasta=&formato=pdf|xlsx|csv: mi registro del periodo. */
    public function download(Request $request, PeopleReports $reports, RegisterFiles $files): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $format = ExportFormat::tryFrom((string) $request->query('formato')) ?? ExportFormat::Pdf;
        [$from, $to] = $this->periodFrom($request);

        $document = $reports->personRegister($user, $from, $to);
        $file = $files->write($document, $format, ['users' => [$user->id], 'from' => $from, 'to' => $to], $user);

        return $this->sendRegisterFile($file);
    }

    /**
     * @return array<string, mixed>
     */
    public static function close(MonthClose $close, User $viewer): array
    {
        $mine = $viewer->id === $close->user_id;

        return [
            'id' => $close->id,
            'user_id' => $close->user_id,
            'month' => $close->monthKey(),
            'version' => $close->version,
            'status' => $close->status->value,
            'worked_minutes' => $close->worked_minutes,
            'expected_minutes' => $close->expected_minutes,
            'difference_minutes' => $close->difference_minutes,
            'overtime_minutes' => $close->overtime_minutes,
            'totals' => $close->totals,
            'generated_at' => $close->generated_at->toIso8601ZuluString(),
            'confirmed_at' => $close->confirmed_at?->toIso8601ZuluString(),
            'disagreed_at' => $close->disagreed_at?->toIso8601ZuluString(),
            'disagreement_note' => $close->disagreement_note,
            'reopened_at' => $close->reopened_at?->toIso8601ZuluString(),
            'reopened_by' => $close->reopener?->name,
            'reopen_reason' => $close->reopen_reason,
            'pdf_sha256' => $close->pdf_sha256,
            'content_hash' => $close->content_hash,
            'register_seq' => $close->register_seq,
            'can' => [
                'confirm' => $mine && in_array($close->status->value, ['pending', 'disagreed'], true),
                'disagree' => $mine && $close->status->value === 'pending',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decision(OvertimeDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'user_id' => $decision->user_id,
            'date' => $decision->date->toDateString(),
            'hour_type' => $decision->hour_type->value,
            'excess_minutes' => $decision->excess_minutes,
            'overtime_minutes' => $decision->overtime_minutes,
            'flex_minutes' => $decision->flex_minutes,
            'destination' => $decision->destination?->value,
            'note' => $decision->note,
            'decided_by' => $decision->decider->name,
            'decided_at' => $decision->created_at?->toIso8601ZuluString(),
            'rest_minutes' => $decision->destination?->value === 'compensate' ? OvertimeService::restMinutes($decision->overtime_minutes) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function movement(TimeBalanceMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'date' => $movement->date->toDateString(),
            'kind' => $movement->kind->value,
            'minutes' => $movement->minutes,
            'reason' => $movement->reason,
            'author' => $movement->author?->name,
            'created_at' => $movement->created_at?->toIso8601ZuluString(),
        ];
    }
}
