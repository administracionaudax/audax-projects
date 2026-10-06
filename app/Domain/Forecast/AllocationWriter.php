<?php

namespace App\Domain\Forecast;

use App\Enums\AllocationMode;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Único punto de escritura de las asignaciones (docs/PLAN-CARGAS.md §5 y §6.2, D-282), como
 * TimeEntryWriter con las horas. Comprueba las reglas que la base no puede (o no puede en SQLite):
 *
 * - de un proyecto real **o** de un previsto, y ese contenedor admite cambios (un previsto abierto
 *   o confirmado; un proyecto no archivado). Las de un previsto vinculado o perdido quedan
 *   congeladas (la línea base, D-286),
 * - de una persona de plantilla activa **o** de un departamento («hueco»), nunca de las dos,
 * - el modo con su cantidad: minutos (total, por día, por mes) o porcentaje (1–200 %),
 * - desde ≤ hasta; sin fin, solo el modo mensual; como mucho tres años.
 *
 * Los permisos los comprueban AllocationPolicy y ForecastProjectPolicy antes de llamar aquí.
 */
final class AllocationWriter
{
    /** Duración máxima de una asignación (días). */
    public const int MAX_SPAN_DAYS = 3 * 366;

    /**
     * @param  array<string, mixed>  $data  user_id | department_id, mode, minutes | percent, start_date, end_date, note
     */
    public function create(Project|ForecastProject $container, array $data, User $by): Allocation
    {
        $this->assertEditable($container);

        return Allocation::query()->create([
            ...$this->normalize($data),
            'project_id' => $container instanceof Project ? $container->id : null,
            'forecast_project_id' => $container instanceof ForecastProject ? $container->id : null,
            'created_by' => $by->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Allocation $allocation, array $data): Allocation
    {
        $this->assertEditable($this->container($allocation));

        $allocation->fill($this->normalize([...$this->current($allocation), ...$data]))->save();

        return $allocation;
    }

    /**
     * «Asignar a…» (§5.2 paso 7): un hueco pasa a una persona y conserva modo, cantidad y fechas.
     */
    public function assign(Allocation $allocation, User $person): Allocation
    {
        if (! $allocation->isGap()) {
            throw ValidationException::withMessages(['user_id' => __('forecast.errors.not_a_gap')]);
        }

        return $this->update($allocation, ['user_id' => $person->id, 'department_id' => null]);
    }

    public function delete(Allocation $allocation): void
    {
        $this->assertEditable($this->container($allocation));

        $allocation->delete();
    }

    /**
     * Copia una asignación de un previsto al proyecto real al vincular (§6.6.3): mismas personas o
     * huecos, modo, cantidad y fechas, con el enlace a la original. Sin comprobar la persona: es
     * una copia de lo que ya se planificó.
     */
    public function copyTo(Allocation $allocation, Project $project, User $by): Allocation
    {
        return Allocation::query()->create([
            'project_id' => $project->id,
            'forecast_project_id' => null,
            'user_id' => $allocation->user_id,
            'department_id' => $allocation->department_id,
            'mode' => $allocation->mode,
            'minutes' => $allocation->minutes,
            'percent' => $allocation->percent,
            'start_date' => $allocation->start_date->toDateString(),
            'end_date' => $allocation->end_date?->toDateString(),
            'note' => $allocation->note,
            'copied_from_allocation_id' => $allocation->id,
            'created_by' => $by->id,
        ]);
    }

    /**
     * ¿Admite cambios? Un previsto abierto o confirmado; un proyecto no archivado ni borrado.
     */
    public static function editable(Project|ForecastProject $container): bool
    {
        if ($container instanceof ForecastProject) {
            return ! $container->trashed() && $container->status->isEditable();
        }

        return ! $container->trashed() && $container->acceptsTime();
    }

    private function assertEditable(Project|ForecastProject|null $container): void
    {
        if ($container === null || ! self::editable($container)) {
            throw ValidationException::withMessages(['allocation' => __('forecast.errors.frozen')]);
        }
    }

    private function container(Allocation $allocation): Project|ForecastProject|null
    {
        $allocation->loadMissing($allocation->project_id !== null ? 'project' : 'forecastProject');

        return $allocation->project_id !== null ? $allocation->project : $allocation->forecastProject;
    }

    /**
     * @return array<string, mixed>
     */
    private function current(Allocation $allocation): array
    {
        return [
            'user_id' => $allocation->user_id,
            'department_id' => $allocation->department_id,
            'mode' => $allocation->mode->value,
            'minutes' => $allocation->minutes,
            'percent' => $allocation->percent,
            'start_date' => $allocation->start_date->toDateString(),
            'end_date' => $allocation->end_date?->toDateString(),
            'note' => $allocation->note,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user_id: int|null, department_id: int|null, mode: AllocationMode, minutes: int|null, percent: int|null, start_date: string, end_date: string|null, note: string|null}
     */
    private function normalize(array $data): array
    {
        $userId = isset($data['user_id']) && is_numeric($data['user_id']) ? (int) $data['user_id'] : null;
        $departmentId = isset($data['department_id']) && is_numeric($data['department_id']) ? (int) $data['department_id'] : null;

        if (($userId === null) === ($departmentId === null)) {
            throw ValidationException::withMessages(['user_id' => __('forecast.errors.person_or_gap')]);
        }

        if ($userId !== null) {
            $user = User::query()->find($userId);

            if ($user === null || ! ForecastPeople::assignable($user)) {
                throw ValidationException::withMessages(['user_id' => __('forecast.errors.person_not_assignable')]);
            }
        }

        if ($departmentId !== null && ! Department::query()->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => __('forecast.errors.department_missing')]);
        }

        $raw = $data['mode'] ?? null;
        $mode = $raw instanceof AllocationMode ? $raw : AllocationMode::tryFrom(is_string($raw) ? $raw : '');

        if ($mode === null) {
            throw ValidationException::withMessages(['mode' => __('forecast.errors.mode')]);
        }

        $minutes = isset($data['minutes']) && is_numeric($data['minutes']) ? (int) $data['minutes'] : null;
        $percent = isset($data['percent']) && is_numeric($data['percent']) ? (int) $data['percent'] : null;

        if ($mode->usesMinutes() && ($minutes === null || $minutes < 1 || $minutes > Allocation::MINUTES_MAX)) {
            throw ValidationException::withMessages(['minutes' => __('forecast.errors.minutes')]);
        }

        if (! $mode->usesMinutes() && ($percent === null || $percent < 1 || $percent > Allocation::PERCENT_MAX)) {
            throw ValidationException::withMessages(['percent' => __('forecast.errors.percent', ['max' => Allocation::PERCENT_MAX])]);
        }

        $start = self::date($data['start_date'] ?? null);
        $end = self::date($data['end_date'] ?? null);

        if ($start === null) {
            throw ValidationException::withMessages(['start_date' => __('forecast.errors.start_date')]);
        }

        if ($end === null && ! $mode->allowsOpenEnd()) {
            throw ValidationException::withMessages(['end_date' => __('forecast.errors.end_required')]);
        }

        if ($end !== null && $end < $start) {
            throw ValidationException::withMessages(['end_date' => __('forecast.errors.end_before_start')]);
        }

        if ($end !== null && CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > self::MAX_SPAN_DAYS) {
            throw ValidationException::withMessages(['end_date' => __('forecast.errors.too_long')]);
        }

        $note = isset($data['note']) && is_string($data['note']) ? trim($data['note']) : null;

        return [
            'user_id' => $userId,
            'department_id' => $userId !== null ? null : $departmentId,
            'mode' => $mode,
            'minutes' => $mode->usesMinutes() ? $minutes : null,
            'percent' => $mode->usesMinutes() ? null : $percent,
            'start_date' => $start,
            'end_date' => $end,
            'note' => $note === '' ? null : mb_substr((string) $note, 0, Allocation::NOTE_MAX),
        ];
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value ? $value : null;
    }
}
