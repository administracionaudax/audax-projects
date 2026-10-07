<?php

namespace App\Http\Controllers\Leave;

use App\Domain\Absences\LeaveCalendar;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\People\PeopleAccess;
use App\Domain\Reports\ReportCache;
use App\Enums\AbsenceStatus;
use App\Enums\LeaveCalendarDayKind;
use App\Http\Controllers\Absences\AbsencesController;
use App\Models\Absence;
use App\Models\Holiday;
use App\Models\LeaveCalendarDay;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Calendario laboral» (`/ausencias/calendario?anio=2026`, Fase 11, R3; W-038, W-039 y W-076; L-23;
 * D-366 y D-367): el calendario del año que la empresa tiene que tener a la vista de la plantilla
 * (art. 34.6 ET), con los festivos (nacionales, autonómicos, locales y de empresa, con su fuente), los
 * días de media jornada, los días bloqueados para las vacaciones y, para quien mira, sus ausencias
 * («Mi calendario» de Woffu). Lo ve toda la plantilla con el módulo; RR. HH. añade y quita los días
 * de media jornada y los bloqueados (los festivos, en /admin/festivos).
 */
class LeaveCalendarController extends AbsencesController
{
    public function index(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $current = (int) LocalTime::today()->format('Y');
        $year = $request->integer('anio', $current);
        $year = $year >= SpanishNationalHolidays::MIN_YEAR && $year <= SpanishNationalHolidays::MAX_YEAR ? $year : $current;
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        return Inertia::render('leave/calendar', [
            'year' => $year,
            'current_year' => $current,
            'today' => LocalTime::todayString(),
            'work_center' => (string) (Setting::get('people_work_center') ?: 'València (Valencia)'),
            'holidays' => Holiday::query()->whereBetween('date', [$from, $to])->orderBy('date')->get()->map(fn (Holiday $holiday): array => [
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'level' => $holiday->level?->value,
                'source' => $holiday->source,
            ])->values()->all(),
            'special_days' => LeaveCalendarDay::query()
                ->where('start_date', '<=', $to)
                ->where('end_date', '>=', $from)
                ->orderBy('start_date')
                ->orderBy('id')
                ->get()
                ->map(fn (LeaveCalendarDay $day): array => [
                    'id' => $day->id,
                    'kind' => $day->kind->value,
                    'name' => $day->name,
                    'start_date' => $day->start_date->toDateString(),
                    'end_date' => $day->end_date->toDateString(),
                ])->values()->all(),
            'absences' => Absence::query()
                ->with('leaveType:id,name')
                ->where('user_id', $viewer->id)
                ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
                ->overlapping($from, $to)
                ->orderBy('start_date')
                ->orderBy('id')
                ->get()
                ->map(fn (Absence $absence): array => [
                    'id' => $absence->id,
                    'name' => $absence->leaveType->name ?? $absence->type->label(),
                    'status' => $absence->status->value,
                    'start_date' => $absence->start_date->toDateString(),
                    'end_date' => $absence->end_date->toDateString(),
                    'partial_minutes' => $absence->partial_minutes,
                ])->values()->all(),
            'can' => [
                'manage' => PeopleAccess::managesRegister($viewer),
                'holidays' => $viewer->can('manage-settings'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(LeaveCalendarDayKind::class)],
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [], (array) __('leave.attributes'));

        $end = $data['end_date'] ?? $data['start_date'];
        $overlap = LeaveCalendarDay::query()
            ->where('kind', $data['kind'])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $data['start_date'])
            ->first();

        if ($overlap !== null) {
            throw ValidationException::withMessages(['start_date' => __('leave.errors.calendar_overlap', ['name' => $overlap->name])]);
        }

        $day = LeaveCalendarDay::query()->create([
            'kind' => $data['kind'],
            'name' => trim((string) $data['name']),
            'start_date' => $data['start_date'],
            'end_date' => $end,
            'created_by' => $request->user()?->id,
        ]);

        $this->changed();
        $this->toast((string) __('leave.flash.calendar_day_saved', ['name' => $day->name]));

        return back();
    }

    public function destroy(LeaveCalendarDay $day): RedirectResponse
    {
        $day->delete();
        $this->changed();
        $this->toast((string) __('leave.flash.calendar_day_deleted', ['name' => $day->name]));

        return back();
    }

    /** La media jornada cambia la capacidad: se olvida lo leído y la caché de los informes. */
    private function changed(): void
    {
        LeaveCalendar::forget();
        ReportCache::bump();
    }
}
