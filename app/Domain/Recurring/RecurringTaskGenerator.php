<?php

namespace App\Domain\Recurring;

use App\Domain\Tasks\TaskWriter;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Crea las instancias de las tareas recurrentes (SPEC §4.3, D-059). Lo ejecuta cada día el comando
 * tasks:generate-recurring. Idempotente: la clave única (regla, fecha) impide duplicar, y
 * last_generated_on evita repasar lo ya hecho. Si hace días que no se ejecuta, recupera como mucho
 * los MAX_CATCH_UP_DAYS días anteriores (y, como red, MAX_CATCH_UP instancias por regla). Se salta
 * las reglas de proyectos archivados y da esos días por pasados (al recuperar el proyecto no se
 * crean de golpe las tareas atrasadas); si una instancia no se puede crear (p. ej. la bolsa está
 * cerrada), lo anota en el log y sigue con las demás.
 * Un responsable desactivado no impide crearla: la tarea queda sin responsable (y un tipo de tarea
 * desactivado, sin tipo).
 * generateFor() es la generación inmediata al crear, editar o reactivar una regla.
 */
final class RecurringTaskGenerator
{
    /** Días atrasados que se recuperan como mucho (D-059). */
    public const int MAX_CATCH_UP_DAYS = 31;

    /** Instancias por regla y ejecución como mucho: red de seguridad (31 días caben de sobra). */
    public const int MAX_CATCH_UP = 31;

    public function __construct(private readonly TaskWriter $writer) {}

    /**
     * @return int tareas creadas
     */
    public function generate(CarbonImmutable $today): int
    {
        $today = self::day($today);
        $created = 0;

        // Reglas de proyectos archivados: no crean nada y los días que pasan archivados quedan como
        // generados (como al reactivar una regla, generateFor()). Así, al recuperar el proyecto no
        // se crean de golpe las tareas de las semanas o meses que estuvo archivado.
        RecurringTaskRule::query()
            ->where('is_active', true)
            ->where('starts_on', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('last_generated_on')->orWhere('last_generated_on', '<', $today->toDateString()))
            ->whereHas('project', fn ($q) => $q->where('status', ProjectStatus::Archived->value))
            ->update(['last_generated_on' => $today->toDateString()]);

        $rules = RecurringTaskRule::query()
            ->where('is_active', true)
            ->where('starts_on', '<=', $today->toDateString())
            ->whereHas('project', fn ($q) => $q->where('status', '!=', ProjectStatus::Archived->value))
            ->with('project')
            ->get();

        foreach ($rules as $rule) {
            $from = $rule->last_generated_on !== null ? $rule->last_generated_on->addDay() : $rule->starts_on;
            // Como mucho, los últimos MAX_CATCH_UP_DAYS días (D-059), no las últimas 31 instancias:
            // una regla semanal o mensual no recupera meses de tareas atrasadas.
            $from = CarbonImmutable::parse($from->toDateString())->max($today->subDays(self::MAX_CATCH_UP_DAYS));
            $dates = array_slice($rule->occurrencesBetween($from, $today), -self::MAX_CATCH_UP);
            $actor = $this->actorFor($rule);

            foreach ($dates as $date) {
                if ($actor === null) {
                    break;
                }

                if ($this->createInstance($rule, $date, $actor) !== null) {
                    $created++;
                }
            }

            $rule->forceFill(['last_generated_on' => $today->toDateString()])->saveQuietly();
        }

        return $created;
    }

    /**
     * Generación inmediata (D-059): al crear, editar o reactivar una regla activa, crea ya la
     * instancia de hoy si hoy toca y aún no existe. Al crearla o reactivarla ($startFromToday) no
     * recupera fechas anteriores: la regla queda marcada como generada hasta hoy y el comando diario
     * sigue desde mañana (una regla que empieza en el pasado o que vuelve a activarse no llena el
     * proyecto de tareas atrasadas). Al editar una regla que ya estaba activa, no cambia lo que el
     * comando diario tenga pendiente.
     *
     * @return Task|null la tarea de hoy, si se ha creado ahora
     */
    public function generateFor(RecurringTaskRule $rule, CarbonImmutable $today, bool $startFromToday = true): ?Task
    {
        $today = self::day($today);
        $rule->loadMissing('project');

        if (! $rule->is_active || $rule->project->status === ProjectStatus::Archived) {
            return null;
        }

        $date = $today->toDateString();
        $task = null;

        if ($rule->occurrencesBetween($today, $today) !== []) {
            $actor = $this->actorFor($rule);
            $task = $actor !== null ? $this->createInstance($rule, $date, $actor) : null;
        }

        if ($startFromToday && ($rule->last_generated_on === null || $rule->last_generated_on->toDateString() < $date)) {
            $rule->forceFill(['last_generated_on' => $date])->saveQuietly();
        }

        return $task;
    }

    /**
     * El día como fecha a medianoche en la zona de la app, como las fechas de las reglas
     * (starts_on…): LocalTime::today() es la medianoche de Madrid, que en UTC aún es el día
     * anterior, y compararla con las fechas de la regla dejaría fuera la de hoy.
     */
    public static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }

    /**
     * Crea la instancia de una fecha con TaskWriter, salvo que ya exista (también en la papelera).
     */
    private function createInstance(RecurringTaskRule $rule, string $date, User $actor): ?Task
    {
        if (Task::query()->withTrashed()->where('recurring_task_rule_id', $rule->id)->where('occurrence_date', $date)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($rule, $date, $actor): Task {
                $occurrence = CarbonImmutable::parse($date);
                $task = $this->writer->create($actor, $rule->project, [
                    'title' => $rule->title,
                    'description' => $rule->description,
                    'task_type_id' => $this->typeFor($rule),
                    'assignee_user_id' => $this->assigneeFor($rule),
                    'estimated_minutes' => $rule->estimated_minutes,
                    'priority' => $rule->priority,
                    'hour_bank_id' => $rule->hour_bank_id,
                    'start_date' => $occurrence->toDateString(),
                    'due_date' => $occurrence->addDays($rule->due_offset_days)->toDateString(),
                ]);
                $task->forceFill(['recurring_task_rule_id' => $rule->id, 'occurrence_date' => $date])->save();

                return $task;
            });
        } catch (ValidationException $exception) {
            Log::warning('Tarea recurrente no creada', ['rule' => $rule->id, 'date' => $date, 'errors' => $exception->errors()]);
        } catch (UniqueConstraintViolationException) {
            // La creó a la vez otra ejecución (el comando diario y la generación inmediata).
        }

        return null;
    }

    /**
     * El responsable de la regla si sigue activo; si está de baja, la tarea va sin responsable
     * (D-059) para que no se pierda la instancia.
     */
    private function assigneeFor(RecurringTaskRule $rule): ?int
    {
        if ($rule->assignee_user_id === null) {
            return null;
        }

        return User::query()->whereKey($rule->assignee_user_id)->active()->internal()->exists()
            ? $rule->assignee_user_id
            : null;
    }

    /**
     * El tipo de la regla si sigue activo; si el admin lo ha desactivado, la tarea va sin tipo.
     */
    private function typeFor(RecurringTaskRule $rule): ?int
    {
        if ($rule->task_type_id === null) {
            return null;
        }

        return TaskType::query()->active()->whereKey($rule->task_type_id)->exists() ? $rule->task_type_id : null;
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
