<?php

namespace App\Http\Controllers\Inspection;

use App\Domain\People\InspectionAccesses;
use App\Domain\People\Reports\InspectionExport;
use App\Domain\People\Reports\PeopleFormat;
use App\Domain\People\Reports\RegisterDataset;
use App\Domain\People\Reports\RegisterScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\InspectionAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * El acceso de solo lectura de la Inspección de Trabajo (PLAN-FASE-11 §6.4 y §11.3; D-353):
 *
 * - `/inspeccion/acceso/{enlace}`: pide el código (el segundo factor, que RR. HH. entrega por otra
 *   vía) y abre la sesión,
 * - `/inspeccion`: las personas y el periodo de su ámbito y la descarga de la exportación,
 * - `/inspeccion/personas/{persona}?mes=`: el registro de una persona, mes a mes,
 * - `/inspeccion/exportar`: el ZIP de todo su ámbito.
 *
 * Fuera del resto de la app: no es una cuenta de usuario, no ve nada más y no cambia nada. Cada
 * consulta queda en la auditoría. Las ausencias salen sin su tipo (minimización).
 */
class InspectionPortalController extends Controller
{
    use HandlesRegisterFiles;

    public function __construct(private readonly InspectionAccesses $accesses) {}

    public function show(string $token): Response
    {
        $access = $this->accesses->byToken($token);
        abort_if($access === null || ! InspectionAccesses::enabled() || $access->revoked_at !== null, 404);

        return Inertia::render('inspection/access', [
            'token' => $token,
            'name' => $access->name,
            'usable' => $access->usable(),
            'state' => $access->state(),
        ]);
    }

    public function login(Request $request, string $token): RedirectResponse
    {
        $access = $this->accesses->byToken($token);
        abort_if($access === null || ! InspectionAccesses::enabled(), 404);

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $this->accesses->attempt($request, $access, $code);

        return redirect()->route('inspection.index');
    }

    public function index(Request $request): Response
    {
        $access = self::access($request);
        [$from, $to] = $this->accesses->period($access);
        $this->accesses->log($access, $request, 'index');

        return Inertia::render('inspection/index', [
            'access' => self::accessProps($access),
            'period' => ['from' => $from, 'to' => $to],
            'months' => self::months($from, $to),
            'people' => array_map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name], $this->accesses->people($access)),
        ]);
    }

    public function person(Request $request, User $person, RegisterDataset $dataset): Response
    {
        $access = self::access($request);
        $people = $this->accesses->people($access);
        abort_unless(in_array($person->id, array_map(fn (User $user): int => $user->id, $people), true), 404);

        [$scopeFrom, $scopeTo] = $this->accesses->period($access);
        $months = self::months($scopeFrom, $scopeTo);
        $month = in_array((string) $request->query('mes'), $months, true) ? (string) $request->query('mes') : ($months[count($months) - 1] ?? substr($scopeTo, 0, 7));
        $from = max($scopeFrom, $month.'-01');
        $to = min($scopeTo, CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString());

        $entry = $dataset->build([$person], $from, $to, true)[$person->id];
        $this->accesses->log($access, $request, 'person', ['user_id' => $person->id, 'month' => $month]);

        return Inertia::render('inspection/person', [
            'access' => self::accessProps($access),
            'person' => ['id' => $person->id, 'name' => $person->name],
            'month' => $month,
            'months' => $months,
            'lines' => array_values(array_map(fn (array $line): array => [
                'date' => $line['date'],
                'expected_minutes' => $line['expected_minutes'],
                'worked_minutes' => $line['worked_minutes'],
                'pause_minutes' => $line['pause_minutes'],
                'first_in' => $line['first_in'],
                'last_out' => $line['last_out'],
                'segments' => PeopleFormat::segments($line['segments'])['work'],
                'overtime_minutes' => $line['overtime_minutes'] + $line['complementary_minutes'],
                'modes' => $line['modes'],
                'incidents' => $line['incidents'],
                'holiday' => $line['holiday'],
                'absence' => $line['absence'],
                'registered' => $line['registered'],
            ], array_filter($entry['lines'], fn (array $line): bool => $line['status'] !== 'future'))),
            'totals' => $entry['totals'],
        ]);
    }

    public function export(Request $request, InspectionExport $export): HttpResponse
    {
        $access = self::access($request);
        [$from, $to] = $this->accesses->period($access);
        $people = $this->accesses->people($access);
        $this->accesses->log($access, $request, 'export', ['from' => $from, 'to' => $to]);

        $scope = new RegisterScope($people, $from, $to, (string) __('people.inspection.portal_scope', ['name' => $access->name]), hideAbsenceType: true);

        return $this->sendRegisterFile($export->build($scope, null, $access));
    }

    public function logout(Request $request): RedirectResponse
    {
        $access = $this->accesses->current($request);

        if ($access !== null) {
            $this->accesses->log($access, $request, 'logout');
        }

        $this->accesses->logout($request);

        return redirect()->route('login');
    }

    private static function access(Request $request): InspectionAccess
    {
        $access = $request->attributes->get('inspection_access');
        abort_unless($access instanceof InspectionAccess, 404);

        return $access;
    }

    /**
     * @return array<string, mixed>
     */
    private static function accessProps(InspectionAccess $access): array
    {
        return [
            'name' => $access->name,
            'reference' => $access->reference,
            'valid_until' => $access->valid_until->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function months(string $from, string $to): array
    {
        $months = [];
        $cursor = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end) && count($months) < 60) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }
}
