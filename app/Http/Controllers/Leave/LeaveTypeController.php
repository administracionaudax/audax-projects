<?php

namespace App\Http\Controllers\Leave;

use App\Domain\Absences\LeaveFormat;
use App\Domain\Absences\LeaveLedger;
use App\Domain\Absences\LeavePresenter;
use App\Enums\AbsenceType;
use App\Enums\LeaveUnit;
use App\Http\Controllers\Absences\AbsencesController;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Tipos de ausencia» (`/ausencias/tipos`, Fase 11, R3; W-057 a W-059; D-360 y D-361): el catálogo,
 * solo para RR. HH. (`manage-people`). Crear, editar y desactivar (nunca borrar: las ausencias lo
 * citan). Los cinco de siempre conservan su categoría. El segundo nivel de aprobación solo se puede
 * activar en las vacaciones (P4). Si cambia la asignación anual, se recalcula el año en curso y el
 * siguiente de toda la plantilla (movimientos nuevos en el libro, nunca se reescribe el anterior).
 * Cada cambio queda en la auditoría (LogsDomainActivity, `leave_types`).
 */
class LeaveTypeController extends AbsencesController
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    public function index(): Response
    {
        return Inertia::render('leave/types', [
            'types' => LeaveType::query()->orderBy('sort')->orderBy('id')->get()->map(fn (LeaveType $type): array => [
                ...LeavePresenter::type($type),
                'second_approval' => $type->second_approval,
                'carry_over_until' => $type->carry_over_until,
                'advisor_note' => $type->advisor_note,
                'legacy' => in_array($type->key, AbsenceType::values(), true),
                'sort' => $type->sort,
            ])->values()->all(),
            'categories' => AbsenceType::values(),
            'units' => LeaveUnit::values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $key = Str::limit(Str::slug($data['name'], '_'), 30, '');
        $base = $key !== '' ? $key : 'tipo';
        $key = $base;
        for ($i = 2; LeaveType::query()->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        $type = new LeaveType;
        $type->forceFill(['key' => $key, 'sort' => ((int) LeaveType::query()->max('sort')) + 10]);
        $type->fill($data)->save();

        $this->resync($request, $type);
        $this->toast((string) __('leave.flash.type_created', ['name' => $type->name]));

        return back();
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $data = $this->validated($request, $leaveType);
        $allowance = [$leaveType->annual_allowance, $leaveType->allowance_in_days, $leaveType->unit, $leaveType->carry_over_until];

        $leaveType->fill($data)->save();

        if ($allowance !== [$leaveType->annual_allowance, $leaveType->allowance_in_days, $leaveType->unit, $leaveType->carry_over_until]) {
            $this->resync($request, $leaveType);
        }

        $this->toast((string) __('leave.flash.type_saved', ['name' => $leaveType->name]));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $current): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::enum(AbsenceType::class)],
            'unit' => ['required', Rule::enum(LeaveUnit::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'legal_basis' => ['nullable', 'string', 'max:600'],
            'default_amount' => ['nullable', 'string', 'max:20'],
            'travel_extra' => ['nullable', 'string', 'max:20'],
            'annual_allowance' => ['nullable', 'string', 'max:20'],
            'allowance_in_days' => ['boolean'],
            'paid' => ['boolean'],
            'requires_document' => ['boolean'],
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'health_data' => ['boolean'],
            'carry_over_until' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'allow_without_balance' => ['boolean'],
            'second_approval' => ['boolean'],
            'respects_blocked_days' => ['boolean'],
            'advisor_pending' => ['boolean'],
            'advisor_note' => ['nullable', 'string', 'max:600'],
            'active' => ['boolean'],
        ], ['carry_over_until.regex' => __('leave.errors.carry_over_format')], (array) __('leave.attributes'));

        $unit = LeaveUnit::from((string) $data['unit']);
        $category = AbsenceType::from((string) $data['category']);
        $errors = [];

        // Los cinco de siempre conservan su categoría: la Fase 3 los reconoce por ella.
        if ($current !== null && in_array($current->key, AbsenceType::values(), true)) {
            $category = $current->category;
        }

        if (($data['second_approval'] ?? false) && $category !== AbsenceType::Vacation) {
            $errors['second_approval'][] = __('leave.errors.second_approval_vacation');
        }

        $amounts = [];
        foreach (['default_amount', 'travel_extra', 'annual_allowance'] as $field) {
            $raw = trim((string) ($data[$field] ?? ''));
            $fieldUnit = $field === 'annual_allowance' && ($data['allowance_in_days'] ?? false) ? LeaveUnit::WorkingDays : $unit;
            $amounts[$field] = $raw === '' ? null : LeaveFormat::parse($raw, $fieldUnit);

            if ($raw !== '' && ($amounts[$field] === null || $amounts[$field] < 0)) {
                $errors[$field][] = __('leave.import.bad_amount');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            ...Arr::except($data, ['default_amount', 'travel_extra', 'annual_allowance']),
            ...$amounts,
            'category' => $category,
            'unit' => $unit,
            'allowance_in_days' => $unit === LeaveUnit::Hours && (bool) ($data['allowance_in_days'] ?? false),
            'annual_allowance' => ($amounts['annual_allowance'] ?? 0) > 0 ? $amounts['annual_allowance'] : null,
        ];
    }

    /** Recalcula el año en curso y el siguiente con la asignación nueva. */
    private function resync(Request $request, LeaveType $type): void
    {
        /** @var User $actor */
        $actor = $request->user();
        $year = (int) LocalTime::today()->format('Y');

        foreach ([$year, $year + 1] as $item) {
            $this->ledger->syncYear($item, type: $type, actor: $actor);
        }
    }
}
