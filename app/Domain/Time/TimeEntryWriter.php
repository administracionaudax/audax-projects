<?php

namespace App\Domain\Time;

use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ÚNICA vía para crear, editar y borrar entradas de horas (entrada manual, hoja semanal,
 * temporizador, imputación en nombre de otro). En una transacción:
 *   1. bloquea las bolsas afectadas (lockForUpdate) para que la política `block` sea segura,
 *   2. aplica TimeEntryRules (SPEC §7 y §8),
 *   3. copia proyecto y bolsa de la tarea (SPEC §4.4) y guarda,
 *   4. el modelo TimeEntry recalcula las bolsas con HourBankLedger (D-019).
 *
 * Modo de importación (import(), D-136): sin las validaciones de quien imputa a mano, pero con
 * las invariantes de la entrada.
 */
final class TimeEntryWriter
{
    public function __construct(
        private readonly TimeEntryRules $rules,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(User $actor, TimeEntryData $data): TimeEntryResult
    {
        return DB::transaction(function () use ($actor, $data): TimeEntryResult {
            $task = $this->task($data->taskId);
            $project = $task->project;
            $target = $this->user($actor, $data->userId);
            $bank = $this->lockBanks([$task->hour_bank_id])[$task->hour_bank_id] ?? null;

            $warnings = $this->rules->check($actor, $target, $task, $project, $bank, $data->date, $data->minutes, $data->description, null, $data->startedAt, $data->endedAt);

            $entry = new TimeEntry([
                'user_id' => $target->id,
                'task_id' => $task->id,
                'project_id' => $project->id,
                'hour_bank_id' => $bank?->id,
                'date' => $data->date->toDateString(),
                'minutes' => $data->minutes,
                'started_at' => $data->startedAt,
                'ended_at' => $data->endedAt,
                'description' => $this->description($data->description),
                'is_billable' => $this->billable($task, $project, $data->isBillable),
                'status' => TimeEntryStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $entry->save();

            return new TimeEntryResult($entry->refresh(), $warnings);
        });
    }

    /**
     * La persona de la entrada no cambia. Si cambia la tarea, se copian su proyecto y su bolsa;
     * si no, la entrada conserva los suyos aunque la tarea se haya movido (SPEC §6).
     *
     * @throws ValidationException
     */
    public function update(User $actor, TimeEntry $entry, TimeEntryData $data): TimeEntryResult
    {
        Gate::forUser($actor)->authorize('update', $entry);

        return DB::transaction(function () use ($actor, $entry, $data): TimeEntryResult {
            /** @var TimeEntry $current */
            $current = TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($data->userId !== $current->user_id) {
                throw ValidationException::withMessages(['user_id' => __('time.errors.owner_change')]);
            }

            $target = $this->user($actor, $current->user_id);
            $taskChanged = $data->taskId !== $current->task_id;
            $task = $this->task($data->taskId);

            if ($taskChanged) {
                $project = $task->project;
                $bankId = $task->hour_bank_id;
            } else {
                /** @var Project $project */
                $project = Project::query()->withTrashed()->findOrFail($current->project_id);
                $bankId = $current->hour_bank_id;
            }

            $banks = $this->lockBanks([$current->hour_bank_id, $bankId]);
            $bank = $bankId !== null ? ($banks[$bankId] ?? null) : null;

            $warnings = $this->rules->check($actor, $target, $task, $project, $bank, $data->date, $data->minutes, $data->description, $current, $data->startedAt, $data->endedAt);

            $current->fill([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'hour_bank_id' => $bank?->id,
                'date' => $data->date->toDateString(),
                'minutes' => $data->minutes,
                'description' => $this->description($data->description),
            ]);

            if ($taskChanged || $data->isBillable !== null) {
                $current->is_billable = $this->billable($task, $project, $data->isBillable);
            }

            // Franja (D-162): con una nueva, se guarda; sin ella, se conserva mientras no cambien la
            // fecha ni los minutos, y se quita si cambian (ya no describiría la entrada).
            if ($data->startedAt !== null || $data->endedAt !== null) {
                $current->started_at = $data->startedAt;
                $current->ended_at = $data->endedAt;
            } elseif ($current->isDirty(['date', 'minutes'])) {
                $current->started_at = null;
                $current->ended_at = null;
            }

            $current->save();

            return new TimeEntryResult($current->refresh(), $warnings);
        });
    }

    /**
     * @throws ValidationException
     */
    public function delete(User $actor, TimeEntry $entry): void
    {
        Gate::forUser($actor)->authorize('delete', $entry);

        DB::transaction(function () use ($actor, $entry): void {
            /** @var TimeEntry $current */
            $current = TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->rules->assertDeletable($actor, $current);
            $this->lockBanks([$current->hour_bank_id]);

            $current->delete();
        });
    }

    /**
     * Modo de importación (D-136): escribe una entrada que llega de otra herramienta.
     *
     * - **Sin** las validaciones de quien imputa a mano (SPEC §7): semana enviada, fecha futura,
     *   pertenencia al proyecto, total del día y saldo con política `block`.
     * - **Con** las invariantes: de 1 min a 24 h por entrada, proyecto y bolsa copiados de la tarea,
     *   nunca facturable en un proyecto interno y una entrada bloqueada nunca se modifica (se
     *   devuelve tal cual).
     * - Se guarda sin eventos del modelo (saveQuietly): ni auditoría por entrada ni recálculo de la
     *   bolsa en cada una. Quien importa llama a HourBankLedger::recalculate una vez por bolsa al
     *   final, sin avisos.
     *
     * @throws InvalidArgumentException si la entrada rompe una invariante
     */
    public function import(TimeEntryImport $data, ?TimeEntry $existing = null): TimeEntry
    {
        if ($existing?->isLocked()) {
            return $existing;
        }

        if ($data->minutes < 1 || $data->minutes > TimeEntry::MAX_MINUTES_PER_DAY) {
            throw new InvalidArgumentException("Duración fuera de rango: {$data->minutes} min.");
        }

        $task = $data->task;
        $project = $task->project;

        $entry = $existing ?? new TimeEntry;
        $entry->fill([
            'user_id' => $data->userId,
            'task_id' => $task->id,
            'project_id' => $project->id,
            'hour_bank_id' => $task->hour_bank_id,
            'date' => $data->date->toDateString(),
            'minutes' => $data->minutes,
            'started_at' => $data->startedAt,
            'ended_at' => $data->endedAt,
            'description' => $this->description($data->description),
            'is_billable' => $this->billable($task, $project, $data->isBillable),
            'status' => $data->status,
            'approved_at' => $data->approvedAt,
            'locked_at' => $data->lockedAt,
            'created_by' => $existing === null ? $data->userId : $existing->created_by,
        ]);

        if ($existing === null && $data->createdAt !== null) {
            $entry->created_at = $data->createdAt;
        }

        if (! $entry->exists || $entry->isDirty()) {
            $entry->saveQuietly();
        }

        return $entry;
    }

    private function task(int $taskId): Task
    {
        /** @var Task $task */
        $task = Task::query()
            ->withTrashed()
            ->with(['project' => fn ($query) => $query->withTrashed()])
            ->findOrFail($taskId);

        return $task;
    }

    private function user(User $actor, int $userId): User
    {
        return $userId === $actor->id ? $actor : User::query()->findOrFail($userId);
    }

    /**
     * Bloquea las bolsas en orden de id (evita interbloqueos) y las devuelve indexadas por id.
     *
     * @param  array<int, int|null>  $ids
     * @return array<int, HourBank>
     */
    private function lockBanks(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));
        sort($ids);

        $banks = [];
        foreach ($ids as $id) {
            /** @var HourBank $bank */
            $bank = HourBank::query()->withTrashed()->with('department')->whereKey($id)->lockForUpdate()->firstOrFail();
            $banks[$id] = $bank;
        }

        return $banks;
    }

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : $description;
    }

    /**
     * Facturable: lo indicado o lo de la tarea; nunca en proyectos internos (SPEC §7).
     */
    private function billable(Task $task, Project $project, ?bool $requested): bool
    {
        if ($project->isInternal()) {
            return false;
        }

        return $requested ?? $task->is_billable;
    }
}
