<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Absences\AbsenceText;
use App\Domain\Absences\HolidayImporter;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\Reports\ReportCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HolidayFileRequest;
use App\Http\Requests\Admin\HolidayRequest;
use App\Http\Requests\Admin\HolidayRowsRequest;
use App\Http\Requests\Admin\HolidayYearRequest;
use App\Models\Holiday;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Festivos (/admin/festivos, D-050, gate manage-settings). Ese día la capacidad es 0 para todas las
 * personas (Capacity), así que afectan a la carga y a los informes.
 * - Lista por año (?anio=2026) con navegación entre años; crear, editar y borrar.
 * - «Añadir los festivos nacionales de España de AAAA», calculados en local, sin duplicar.
 * - Importar un .ics o un CSV: vista previa con el estado y los errores de cada línea (flash
 *   `holiday_import`) y confirmación con los que se añaden.
 * Todo queda en la auditoría (log `holidays`) e invalida la caché de los informes (ReportCache, D-046):
 * la capacidad depende de los festivos.
 */
class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayImporter $importer,
        private readonly SpanishNationalHolidays $national,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $current = (int) LocalTime::now()->format('Y');
        $year = $request->integer('anio', $current);
        if ($year < SpanishNationalHolidays::MIN_YEAR || $year > SpanishNationalHolidays::MAX_YEAR) {
            $year = $current;
        }

        $holidays = Holiday::query()
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('date')
            ->get(['id', 'date', 'name']);
        $taken = $holidays->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => true])->all();

        return Inertia::render('admin/holidays/index', [
            'year' => $year,
            'current_year' => $current,
            'holidays' => $holidays->map(fn (Holiday $holiday): array => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ])->values()->all(),
            'national' => array_map(fn (array $holiday): array => [
                ...$holiday,
                'exists' => isset($taken[$holiday['date']]),
            ], $this->national->forYear($year)),
            'limits' => [
                'min_year' => SpanishNationalHolidays::MIN_YEAR,
                'max_year' => SpanishNationalHolidays::MAX_YEAR,
                'max_rows' => HolidayImporter::MAX_ROWS,
                'max_kilobytes' => HolidayImporter::MAX_KILOBYTES,
            ],
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $holiday = DB::transaction(function () use ($request, $actor): Holiday {
            $holiday = Holiday::query()->create([
                'date' => $request->string('date')->toString(),
                'name' => $request->string('name')->toString(),
            ]);

            $this->log($actor, $holiday, 'holiday_created', ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

            return $holiday;
        });

        ReportCache::bump();
        $this->toast(AbsenceText::get('absences.holidays.created', $this->describe($holiday)));

        return back();
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $old = ['date' => $holiday->date->toDateString(), 'name' => $holiday->name];

        DB::transaction(function () use ($request, $actor, $holiday, $old): void {
            $holiday->fill([
                'date' => $request->string('date')->toString(),
                'name' => $request->string('name')->toString(),
            ])->save();

            $this->log($actor, $holiday, 'holiday_updated', [
                'old' => $old,
                'attributes' => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name],
            ]);
        });

        ReportCache::bump();
        $this->toast(AbsenceText::get('absences.holidays.updated', $this->describe($holiday)));

        return back();
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        Gate::authorize('manage-settings');

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $holiday): void {
            $this->log($actor, $holiday, 'holiday_deleted', ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
            $holiday->delete();
        });

        ReportCache::bump();
        $this->toast(AbsenceText::get('absences.holidays.deleted', $this->describe($holiday)));

        return back();
    }

    /**
     * POST /admin/festivos/nacionales: añade los festivos nacionales del año que aún no estén.
     */
    public function national(HolidayYearRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $year = $request->integer('year');

        $result = $this->importer->store($actor, $this->national->forYear($year), 'holidays_national', ['year' => $year]);

        $this->toast(AbsenceText::choice('absences.holidays.national_added', $result['created'], ['year' => $year]));

        return back();
    }

    /**
     * POST /admin/festivos/importar/vista-previa: lee el fichero y devuelve (flash `holiday_import`)
     * cada festivo con su estado, sin guardar nada. Los eventos que se repiten cada año se toman en
     * el año de la página (`year`; si no llega, el actual).
     */
    public function preview(HolidayFileRequest $request): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $year = $request->filled('year') ? $request->integer('year') : null;

        Inertia::flash('holiday_import', [
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 120),
            ...$this->importer->preview((string) $file->get(), strtolower($file->getClientOriginalExtension()), $year),
        ]);

        return back();
    }

    /**
     * POST /admin/festivos/importar: añade los festivos confirmados (sin duplicar fechas).
     */
    public function import(HolidayRowsRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $this->importer->store($actor, $request->holidayRows(), 'holidays_imported');

        $message = AbsenceText::choice('absences.holidays.imported', $result['created']);
        if ($result['skipped'] > 0) {
            $message .= ' '.AbsenceText::choice('absences.holidays.skipped', $result['skipped']);
        }

        $this->toast($message);

        return back();
    }

    /**
     * @return array{name: string, date: string}
     */
    private function describe(Holiday $holiday): array
    {
        return ['name' => $holiday->name, 'date' => $holiday->date->format('d/m/Y')];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, Holiday $holiday, string $event, array $properties): void
    {
        activity('holidays')
            ->performedOn($holiday)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log(AbsenceText::get("absences.activity.{$event}"));
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
