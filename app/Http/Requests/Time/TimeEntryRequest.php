<?php

namespace App\Http\Requests\Time;

use App\Domain\Time\TimeEntryData;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Entrada manual (POST /horas/entradas y PUT /horas/entradas/{entry}): tarea, persona, fecha,
 * duración, descripción y facturable. El formato se valida aquí; las reglas de imputación
 * (SPEC §7 y §8) las aplica TimeEntryWriter.
 */
class TimeEntryRequest extends TimeRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeDuration('minutes');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:1', 'max:'.TimeEntry::MAX_MINUTES_PER_DAY],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Datos para TimeEntryWriter. Sin persona: al crear, la propia; al editar, la de la entrada
     * (el Writer rechaza cambiarla).
     */
    public function toData(User $actor, ?TimeEntry $entry = null): TimeEntryData
    {
        $userId = $this->filled('user_id') ? $this->integer('user_id') : ($entry->user_id ?? $actor->id);

        return new TimeEntryData(
            userId: $userId,
            taskId: $this->integer('task_id'),
            date: CarbonImmutable::parse($this->string('date')->toString()),
            minutes: $this->integer('minutes'),
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            isBillable: $this->has('is_billable') && $this->input('is_billable') !== null ? $this->boolean('is_billable') : null,
        );
    }
}
