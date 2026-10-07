<?php

namespace App\Http\Controllers\People;

use App\Domain\People\InspectionAccesses;
use App\Domain\People\PeopleAccess;
use App\Domain\People\RegisterAnchors;
use App\Domain\People\RegisterIntegrity;
use App\Domain\People\Reports\InspectionExport;
use App\Domain\People\Reports\RegisterScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\InspectionAccess;
use App\Models\MonthClose;
use App\Models\PeopleExport;
use App\Models\RegisterAnchor;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Inspección» (`/personas/inspeccion`, PLAN-FASE-11 §6.3.3 y §11; D-352 y D-353; W-088), solo para
 * RR. HH. (`manage-people`):
 *
 * - **Exportar para la Inspección** un periodo (hasta un año cada vez) y unas personas: un ZIP con
 *   el registro, la cadena, las correcciones, los cierres, las horas extra, las anclas, la
 *   comprobación y un LEEME, al momento (InspectionExport),
 * - la **integridad**: la última comprobación nocturna, las anclas recientes y «Comprobar ahora»,
 * - **comprobar un fichero**: se sube un fichero que salió de la app y se dice si coincide con uno
 *   anotado (su SHA-256) o no,
 * - el **acceso temporal de la Inspección**: encenderlo o apagarlo (apagado por defecto), crear
 *   accesos con ámbito y plazo (el enlace y el código se ven una sola vez) y revocarlos.
 */
class InspectionController extends Controller
{
    use HandlesRegisterFiles;

    public function __construct(private readonly InspectionAccesses $accesses) {}

    public function index(Request $request): Response
    {
        $created = $request->session()->get('inspection_created');

        return Inertia::render('people/inspection', [
            'enabled' => (bool) Setting::get('people_inspection_enabled', false),
            'today' => LocalTime::todayString(),
            'period' => ['from' => LocalTime::today()->startOfMonth()->subMonth()->toDateString(), 'to' => LocalTime::todayString()],
            'max_days' => $this->maxPeriodDays,
            'max_access_days' => InspectionAccess::MAX_DAYS,
            'people' => PeopleAccess::registerSubjects()->get(['id', 'name', 'is_active'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'active' => $user->isActive()])->all(),
            'accesses' => InspectionAccess::query()->with(['creator:id,name', 'revoker:id,name'])->latest('id')->limit(30)->get()->map(fn (InspectionAccess $access): array => self::access($access))->all(),
            'created' => is_array($created) ? $created : null,
            'anchors' => RegisterAnchor::query()->orderByDesc('date')->limit(7)->get()->map(fn (RegisterAnchor $anchor): array => [
                'date' => $anchor->date->toDateString(),
                'digest' => $anchor->digest,
                'events_count' => $anchor->events_count,
                'verified_ok' => $anchor->verified_ok,
                'problems' => $anchor->problems ?? [],
            ])->all(),
            'check' => $request->session()->get('register_check'),
            'verification' => $request->session()->get('file_verification'),
            'exports' => PeopleExport::query()->with(['user:id,name', 'inspectionAccess:id,name'])->where('kind', 'itss')->latest('id')->limit(10)->get()
                ->map(fn (PeopleExport $export): array => RegisterReportController::export($export))->all(),
        ]);
    }

    /** GET /personas/inspeccion/exportar?desde=&hasta=&personas[]=: el ZIP para la Inspección. */
    public function export(Request $request, InspectionExport $export): HttpResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        [$from, $to] = $this->periodFrom($request);
        $ids = $request->query('personas');
        $ids = is_array($ids) ? array_values(array_filter(array_map(intval(...), $ids))) : [];

        $users = array_values(PeopleAccess::registerSubjects()->when($ids !== [], fn ($query) => $query->whereKey($ids))->get()->all());
        $label = $ids === [] ? (string) __('people.reports.scope_all') : implode(', ', array_map(fn (User $user): string => $user->name, $users));

        return $this->sendRegisterFile($export->build(new RegisterScope($users, $from, $to, $label, hideAbsenceType: true), $viewer));
    }

    /** POST /personas/inspeccion/verificar: comprueba la cadena entera ahora. */
    public function check(Request $request, RegisterIntegrity $integrity, RegisterAnchors $anchors): RedirectResponse
    {
        $result = $integrity->verify();
        $request->session()->flash('register_check', [...$result, 'problems' => array_slice($result['problems'], 0, 50), 'at' => CarbonImmutable::now()->toIso8601ZuluString()]);

        activity('people-register')->causedBy($request->user())->event('verified')->withProperties(['ok' => $result['ok'], 'events' => $result['events']])->log('register.verified');

        return back();
    }

    /** POST /personas/inspeccion/comprobar (fichero): ¿coincide con un fichero que salió de la app? */
    public function verifyFile(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $sha = (string) hash_file('sha256', $file->getRealPath());
        $match = PeopleExport::query()->with(['user:id,name', 'inspectionAccess:id,name'])->where('sha256', $sha)->latest('id')->first();
        $close = MonthClose::query()->with('user:id,name')->where('pdf_sha256', $sha)->first();

        $request->session()->flash('file_verification', [
            'name' => $file->getClientOriginalName(),
            'sha256' => $sha,
            'match' => $match === null ? null : RegisterReportController::export($match),
            'close' => $close === null ? null : ['user' => $close->user->name, 'month' => $close->monthKey(), 'version' => $close->version],
        ]);

        return back();
    }

    /** PUT /personas/inspeccion/ajustes {enabled}: enciende o apaga el acceso de la Inspección. */
    public function toggle(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $this->accesses->toggle($actor, $enabled);
        $this->toast(__($enabled ? 'people.flash.inspection_toggled_on' : 'people.flash.inspection_toggled_off'));

        return back();
    }

    /** POST /personas/inspeccion/accesos: crea un acceso; el enlace y el código se ven una vez. */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'reference' => ['nullable', 'string', 'max:120'],
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['integer', 'distinct'],
            'scope_from' => ['required', 'date_format:Y-m-d'],
            'scope_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:scope_from'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['required', 'integer', 'min:1', 'max:'.InspectionAccess::MAX_DAYS],
        ], [], __('people.inspection.attributes'));

        ['access' => $access, 'token' => $token, 'code' => $code] = $this->accesses->create($actor, $data);

        $request->session()->flash('inspection_created', [
            'id' => $access->id,
            'url' => route('inspection.access', ['token' => $token]),
            'code' => $code,
        ]);
        $this->toast(__('people.flash.inspection_created'));

        return back();
    }

    public function revoke(Request $request, InspectionAccess $access): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->accesses->revoke($actor, $access);
        $this->toast(__('people.flash.inspection_revoked'));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function access(InspectionAccess $access): array
    {
        return [
            'id' => $access->id,
            'name' => $access->name,
            'email' => $access->email,
            'reference' => $access->reference,
            'scope_user_ids' => $access->scope_user_ids,
            'scope_from' => $access->scope_from->toDateString(),
            'scope_to' => $access->scope_to->toDateString(),
            'valid_from' => $access->valid_from->toIso8601ZuluString(),
            'valid_until' => $access->valid_until->toIso8601ZuluString(),
            'state' => $access->state(),
            'created_by' => $access->creator->name,
            'created_at' => $access->created_at?->toIso8601ZuluString(),
            'revoked_by' => $access->revoker?->name,
            'revoked_at' => $access->revoked_at?->toIso8601ZuluString(),
            'last_used_at' => $access->last_used_at?->toIso8601ZuluString(),
            'failed_attempts' => $access->failed_attempts,
        ];
    }
}
