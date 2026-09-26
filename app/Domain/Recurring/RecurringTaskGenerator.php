<?php

namespace App\Domain\Recurring;

use App\Domain\Tasks\TaskWriter;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Crea las instancias de las tareas recurrentes (SPEC §4.3, D-056). Lo ejecuta cada día el comando
 * tasks:generate-recurring. Idempotente: la clave única (regla, fecha) impide duplicar, y
 * last_generated_on evita repasar lo ya hecho. Si hace mucho que no se ejecuta, recupera como mucho
 * MAX_CATCH_UP instancias por regla. Se salta las reglas de proyectos archivados; si una instancia
 * no se puede crear (p. ej. la bolsa está cerrada), lo anota en el log y sigue con las demás.
 */
final class RecurringTaskGenerator
{
    public const int MAX_CATCH_UP = 31;

    public function __construct(private readonly TaskWriter $writer) {}

    /**
     * @return int tareas creadas
     */
    public function generate(CarbonImmutable $today): int
    {
        $created = 0;

        $rules = RecurringTaskRule::query()
            ->where('is_active', true)
            ->where('starts_on', '<=', $today->toDateString())
            ->whereHas('project', fn ($q) => $q->where('status', '!=', ProjectStatus::Archived->value))
            ->with('project')
            ->get();

        foreach ($rules as $rule) {
            $from = $rule->last_generated_on !== null ? $rule->last_generated_on->addDay() : $rule->starts_on;
            $dates = array_slice($rule->occurrencesBetween(CarbonImmutable::parse($from->toDateString()), $today), -self::MAX_CATCH_UP);
            $actor = $this->actorFor($rule);

            foreach ($dates as $date) {
                if ($actor === null) {
                    break;
                }
                if (Task::query()->withTrashed()->where('recurring_task_rule_id', $rule->id)->where('occurrence_date', $date)->exists()) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($rule, $date, $actor, &$created): void {
                        $occurrence = CarbonImmutable::parse($date);
                        $task = $this->writer->create($actor, $rule->project, [
                            'title' => $rule->title,
                            'description' => $rule->description,
                            'task_type_id' => $rule->task_type_id,
                            'assignee_user_id' => $rule->assignee_user_id,
                            'estimated_minutes' => $rule->estimated_minutes,
                            'priority' => $rule->priority,
                            'hour_bank_id' => $rule->hour_bank_id,
                            'start_date' => $occurrence->toDateString(),
                            'due_date' => $occurrence->addDays($rule->due_offset_days)->toDateString(),
                        ]);
                        $task->forceFill(['recurring_task_rule_id' => $rule->id, 'occurrence_date' => $date])->save();
                        $created++;
                    });
                } catch (ValidationException $exception) {
                    Log::warning('Tarea recurrente no creada', ['rule' => $rule->id, 'date' => $date, 'errors' => $exception->errors()]);
                }
            }

            $rule->forceFill(['last_generated_on' => $today->toDateString()])->saveQuietly();
        }

        return $created;
    }

    /**
     * Quien «crea» la instancia: quien creó la regla si sigue activo; si no, el primer admin activo.
     */
    private function actorFor(RecurringTaskRule $rule): ?User
    {
        $creator = $rule->created_by !== null ? User::query()->find($rule->created_by) : null;

        if ($creator !== null && $creator->is_active) {
            return $creator;
        }

        return User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();
    }
}
