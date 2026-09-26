<?php

namespace App\Domain\Time;

use App\Domain\HourBanks\HourBankLedger;
use App\Enums\OveragePolicy;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\Duration;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Validaciones de imputación del SPEC §7 y §8 (con D-019, D-033, D-034 y D-036).
 * Errores → ValidationException con todos los mensajes a la vez. Avisos → TimeEntryWarning.
 * Lo usa TimeEntryWriter con la bolsa ya bloqueada; no se llama desde los controladores.
 */
final class TimeEntryRules
{
    public function __construct(
        private readonly HourBankLedger $ledger,
        private readonly Capacity $capacity,
    ) {}

    /**
     * @return list<TimeEntryWarning>
     *
     * @throws ValidationException
     */
    public function check(
        User $actor,
        User $target,
        Task $task,
        Project $project,
        ?HourBank $bank,
        CarbonImmutable $date,
        int $minutes,
        ?string $description,
        ?TimeEntry $existing = null,
    ): array {
        // Solo un admin edita entradas bloqueadas, y sin reabrir la semana (SPEC §7).
        $adminEditingLocked = $existing !== null && $existing->isLocked() && $actor->isAdmin();

        // Una entrada de antes de que el proyecto pasara a bolsas (FirstHourBank no mueve horas) se
        // puede seguir corrigiendo sin bolsa mientras no cambie de tarea.
        $keepsNoBank = $existing !== null
            && $bank === null
            && $existing->getOriginal('hour_bank_id') === null
            && (int) $existing->getOriginal('task_id') === $task->id;

        // Bolsa cerrada o renovada (D-053): sus horas quedan fijas (el saldo registrado al cerrar y
        // el histórico de la renovación no cambian). Se puede corregir la descripción o si es
        // facturable; los minutos, la fecha, la tarea o borrarla, solo un admin (queda auditado).
        $frozen = $existing !== null ? $this->frozenBankOf($existing, $bank) : null;
        $changesConsumption = $existing === null
            || (int) $existing->getOriginal('task_id') !== $task->id
            || $minutes !== (int) $existing->getOriginal('minutes')
            || $date->toDateString() !== CarbonImmutable::parse((string) $existing->getRawOriginal('date'))->toDateString();
        // En su misma bolsa no aplica «no admite horas»: lo que se puede cambiar lo dice bank_frozen.
        $editsFrozenInPlace = $frozen !== null && $bank?->id === $frozen->id;

        $errors = $this->subjectErrors($actor, $target, $task, $project, $bank, $adminEditingLocked || $editsFrozenInPlace, $keepsNoBank);

        if ($frozen !== null && $changesConsumption && ! $actor->isAdmin()) {
            $errors['task_id'][] = $this->frozenMessage($frozen);
        }

        // Fecha
        if ($date->toDateString() > LocalTime::todayString() && ! (bool) Setting::get('allow_future_time_entries', false)) {
            $errors['date'][] = $this->message('time.errors.future_date');
        }
        if (! $adminEditingLocked) {
            $days = [$date->toDateString()];
            if ($existing !== null) {
                $days[] = CarbonImmutable::parse((string) $existing->getRawOriginal('date'))->toDateString();
            }

            $checkedWeeks = [];
            foreach ($days as $day) {
                $weekStart = TimesheetPeriod::weekStartOf($day)->toDateString();
                if (isset($checkedWeeks[$weekStart])) {
                    continue;
                }
                $checkedWeeks[$weekStart] = true;

                $period = TimesheetPeriod::forUserOn($target, $day);
                if (! $period->isEditable()) {
                    $errors['date'][] = $this->message('time.errors.week_closed', [
                        'week' => CarbonImmutable::parse($weekStart)->format('d/m/Y'),
                        'status' => mb_strtolower($period->status->label()),
                    ]);
                }
            }
        }

        // Duración
        $dayTotal = $minutes;
        if ($minutes < 1 || $minutes > TimeEntry::MAX_MINUTES_PER_DAY) {
            $errors['minutes'][] = $this->message('time.errors.minutes_range');
        } else {
            $dayTotal += (int) TimeEntry::query()
                ->where('user_id', $target->id)
                ->where('date', $date->toDateString())
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing?->id))
                ->sum('minutes');

            if ($dayTotal > TimeEntry::MAX_MINUTES_PER_DAY) {
                $errors['minutes'][] = $this->message('time.errors.day_over_24h', ['date' => $date->format('d/m/Y')]);
            }
        }

        // Descripción obligatoria (ajuste)
        if ((bool) Setting::get('time_entry_description_required', false) && trim((string) $description) === '') {
            $errors['description'][] = $this->message('time.errors.description_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Política de exceso `block` (SPEC §8.6): con la bolsa ya bloqueada por el Writer.
        if ($bank !== null) {
            $this->ledger->assertFits($bank, $minutes, $existing);
        }

        return $this->warnings($target, $task, $bank, $date, $minutes, $dayTotal, $existing);
    }

    /**
     * ¿Puede $user iniciar un temporizador en $task? Mismas reglas de persona, tarea, proyecto y
     * bolsa que al imputar, más: su semana actual abierta y, con política `block`, saldo en la
     * bolsa (D-035: no se inicia un temporizador que no podrá imputarse).
     *
     * @throws ValidationException
     */
    public function assertCanStartTimer(User $user, Task $task, Project $project, ?HourBank $bank): void
    {
        $errors = $this->subjectErrors($user, $user, $task, $project, $bank, false);

        $period = TimesheetPeriod::forUserOn($user, LocalTime::today());
        if (! $period->isEditable()) {
            $errors['date'][] = $this->message('time.errors.week_closed', [
                'week' => TimesheetPeriod::weekStartOf(LocalTime::today())->format('d/m/Y'),
                'status' => mb_strtolower($period->status->label()),
            ]);
        }

        if ($bank !== null && $errors === [] && $this->ledger->effectivePolicy($bank) === OveragePolicy::Block && $this->ledger->available($bank) === 0) {
            $errors['task_id'][] = $this->message('time.errors.timer_bank_empty', ['bank' => $bank->name]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Reglas de persona, tarea, proyecto y bolsa (SPEC §7).
     *
     * @return array<string, list<string>>
     */
    private function subjectErrors(User $actor, User $target, Task $task, Project $project, ?HourBank $bank, bool $adminEditingLocked, bool $keepsNoBank = false): array
    {
        $errors = [];

        // Persona
        if (! $target->is_active) {
            $errors['user_id'][] = $this->message('time.errors.user_inactive');
        }
        if ($target->isClient()) {
            $errors['user_id'][] = $this->message('time.errors.user_not_internal');
        }
        if ($actor->id !== $target->id && Gate::forUser($actor)->denies('logTimeFor', [TimeEntry::class, $target, $project])) {
            $errors['user_id'][] = $this->message('time.errors.cannot_log_for');
        }

        // Tarea, proyecto y bolsa
        if ($task->trashed()) {
            $errors['task_id'][] = $this->message('time.errors.task_deleted');
        }
        if (! $project->acceptsTime()) {
            $errors['task_id'][] = $this->message('time.errors.project_archived', ['project' => $project->name]);
        }
        if ($task->is_milestone) {
            $errors['task_id'][] = $this->message('time.errors.milestone');
        }
        if ($project->usesHourBanks() && $bank === null && ! $keepsNoBank) {
            $errors['task_id'][] = $this->message('time.errors.task_without_bank');
        }
        if ($bank !== null && ! $bank->acceptsTime() && ! $adminEditingLocked) {
            $errors['task_id'][] = $this->message('time.errors.bank_closed', ['bank' => $bank->name, 'status' => mb_strtolower($bank->status->label())]);
        }
        if ($bank !== null && $bank->department_id !== null && $target->department_id !== $bank->department_id) {
            $errors['task_id'][] = $this->message('time.errors.bank_department', [
                'bank' => $bank->name,
                'department' => $bank->department->name ?? '',
            ]);
        }
        if (! $project->isInternal() && ! $target->isMemberOf($project)) {
            $errors['task_id'][] = $this->message('time.errors.not_member');
        }

        return $errors;
    }

    /**
     * Borrar una entrada: solo si su semana es editable (o si es un admin con una bloqueada), y si
     * su bolsa está cerrada o renovada, solo un admin (D-053).
     *
     * @throws ValidationException
     */
    public function assertDeletable(User $actor, TimeEntry $entry): void
    {
        if ($entry->isLocked() && $actor->isAdmin()) {
            return;
        }

        $frozen = $this->frozenBankOf($entry, null);

        if ($frozen !== null && ! $actor->isAdmin()) {
            throw ValidationException::withMessages(['task_id' => $this->frozenMessage($frozen)]);
        }

        $period = TimesheetPeriod::forUserOn($entry->user_id, $entry->date);

        if (! $period->isEditable()) {
            throw ValidationException::withMessages([
                'date' => $this->message('time.errors.week_closed', [
                    'week' => $period->week_start->format('d/m/Y'),
                    'status' => mb_strtolower($period->status->label()),
                ]),
            ]);
        }
    }

    /**
     * La bolsa (original) de una entrada existente si está cerrada o renovada.
     */
    private function frozenBankOf(TimeEntry $entry, ?HourBank $loaded): ?HourBank
    {
        $bankId = $entry->getOriginal('hour_bank_id');

        if ($bankId === null) {
            return null;
        }

        $bank = $loaded?->id === (int) $bankId ? $loaded : HourBank::query()->withTrashed()->find((int) $bankId);

        return $bank !== null && ! $bank->acceptsTime() ? $bank : null;
    }

    private function frozenMessage(HourBank $bank): string
    {
        return $this->message('time.errors.bank_frozen', ['bank' => $bank->name, 'status' => mb_strtolower($bank->status->label())]);
    }

    /**
     * @return list<TimeEntryWarning>
     */
    private function warnings(User $target, Task $task, ?HourBank $bank, CarbonImmutable $date, int $minutes, int $dayTotal, ?TimeEntry $existing): array
    {
        $warnings = [];

        if ($task->isCompleted()) {
            $warnings[] = new TimeEntryWarning(TimeEntryWarning::TASK_COMPLETED, $this->message('time.warnings.task_completed'));
        }

        $capacity = $this->capacity->onDate($target, $date);
        if ($dayTotal > $capacity * 1.25) {
            $warnings[] = new TimeEntryWarning(TimeEntryWarning::OVER_CAPACITY, $capacity > 0
                ? $this->message('time.warnings.over_capacity', ['total' => Duration::format($dayTotal), 'capacity' => Duration::format($capacity)])
                : $this->message('time.warnings.no_capacity', ['total' => Duration::format($dayTotal)]));
        }

        $growing = $existing === null
            || (int) $existing->getOriginal('hour_bank_id') !== $bank?->id
            || $minutes > (int) $existing->getOriginal('minutes');

        if ($bank !== null && $growing && $this->ledger->effectivePolicy($bank) === OveragePolicy::Allow) {
            $overage = $this->ledger->projectedOverage($bank, $minutes, $existing);

            if ($overage > 0) {
                $warnings[] = new TimeEntryWarning(TimeEntryWarning::OVERAGE, $overage >= $minutes
                    ? $this->message('time.warnings.overage_all')
                    : $this->message('time.warnings.overage_partial', ['minutes' => Duration::format($overage)]));
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function message(string $key, array $replace = []): string
    {
        $message = __($key, $replace);

        return is_string($message) ? $message : $key;
    }
}
