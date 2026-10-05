<?php

namespace App\Http\Requests\Time;

use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeRange;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Entrada manual (POST /horas/entradas y PUT /horas/entradas/{entry}): tarea, persona, fecha,
 * duración (o franja horaria: hora de inicio y de fin, D-172), descripción y facturable. El formato
 * se valida aquí (la franja, con TimeRange); las reglas de imputación (SPEC §7 y §8) las aplica
 * TimeEntryWriter.
 */
class TimeEntryRequest extends TimeRequest
{
    private ?TimeRange $range = null;

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
            // Con franja horaria, la duración sale de ella (lo que llegue en minutes se ignora).
            'minutes' => ['required_without_all:start_time,end_time', 'nullable', 'integer', 'min:1', 'max:'.TimeEntry::MAX_MINUTES_PER_DAY],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $format = __('time.errors.range_format');
        $incomplete = __('time.errors.range_incomplete');

        return [
            'start_time.date_format' => is_string($format) ? $format : '',
            'end_time.date_format' => is_string($format) ? $format : '',
            'start_time.required_with' => is_string($incomplete) ? $incomplete : '',
            'end_time.required_with' => is_string($incomplete) ? $incomplete : '',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date', 'start_time', 'end_time']) || ! $this->filled('start_time') || ! $this->filled('end_time')) {
                return;
            }

            try {
                $this->range = TimeRange::fromLocal(
                    $this->string('date')->toString(),
                    $this->string('start_time')->toString(),
                    $this->string('end_time')->toString(),
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
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
            minutes: $this->range?->minutes ?? $this->integer('minutes'),
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            startedAt: $this->range?->startedAt,
            endedAt: $this->range?->endedAt,
            isBillable: $this->has('is_billable') && $this->input('is_billable') !== null ? $this->boolean('is_billable') : null,
        );
    }
}
