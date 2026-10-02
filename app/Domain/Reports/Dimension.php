<?php

namespace App\Domain\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Dimensiones por las que se agrupan las horas en los informes (SPEC §10: desgloses y tabla
 * dinámica). Cada una sabe su expresión SQL de agrupación (en SQLite y PostgreSQL) y las uniones
 * que necesita sobre time_entries. Las uniones usan alias report_* para no chocar con los scopes.
 * «Tarea» agrupa por la tarea raíz: las horas de una subtarea suman en su padre (SPEC §6).
 */
enum Dimension: string
{
    case Person = 'persona';
    case Department = 'departamento';
    case Client = 'cliente';
    case Project = 'proyecto';
    case HourBank = 'bolsa';
    case TaskType = 'tipo';
    case Task = 'tarea';
    case Day = 'dia';
    case Week = 'semana';
    case Month = 'mes';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Persona',
            self::Department => 'Departamento',
            self::Client => 'Cliente',
            self::Project => 'Proyecto',
            self::HourBank => 'Bolsa',
            self::TaskType => 'Tipo de tarea',
            self::Task => 'Tarea',
            self::Day => 'Día',
            self::Week => 'Semana',
            self::Month => 'Mes',
        };
    }

    public function isTime(): bool
    {
        return in_array($this, [self::Day, self::Week, self::Month], true);
    }

    /**
     * Expresión SQL de la clave del grupo (literal: nunca lleva datos de fuera).
     *
     * @return literal-string
     */
    public function expression(): string
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        return match ($this) {
            self::Person => 'time_entries.user_id',
            self::Department => 'report_users.department_id',
            self::Client => 'report_projects.client_id',
            self::Project => 'time_entries.project_id',
            self::HourBank => 'time_entries.hour_bank_id',
            self::TaskType => 'report_tasks.task_type_id',
            // Las horas de una subtarea suman en su tarea padre (SPEC §6): se agrupa por la tarea raíz,
            // también si la subtarea está borrada (la unión con tasks no filtra los borrados).
            self::Task => 'COALESCE(report_tasks.parent_task_id, time_entries.task_id)',
            self::Day => $pgsql ? "to_char(time_entries.date, 'YYYY-MM-DD')" : 'time_entries.date',
            // Lunes de la semana (ISO) y primer día del mes, como fecha AAAA-MM-DD.
            self::Week => $pgsql
                ? "to_char(date_trunc('week', time_entries.date), 'YYYY-MM-DD')"
                : "date(time_entries.date, '-6 days', 'weekday 1')",
            self::Month => $pgsql ? "to_char(time_entries.date, 'YYYY-MM-01')" : "strftime('%Y-%m-01', time_entries.date)",
        };
    }

    /**
     * Añade las uniones que necesita la expresión (idempotente: no repite una unión).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function join(Builder $query): void
    {
        self::ensureJoin($query, match ($this) {
            self::Department => 'users',
            self::Client => 'projects',
            self::TaskType, self::Task => 'tasks',
            default => null,
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function ensureJoin(Builder $query, ?string $table): void
    {
        if ($table === null) {
            return;
        }

        $alias = 'report_'.$table;
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if ($join instanceof JoinClause && $join->table === "{$table} as {$alias}") {
                return;
            }
        }

        $foreign = match ($table) {
            'users' => 'time_entries.user_id',
            'projects' => 'time_entries.project_id',
            'tasks' => 'time_entries.task_id',
            'hour_banks' => 'time_entries.hour_bank_id',
            default => throw new \InvalidArgumentException("Unión no prevista: {$table}"),
        };

        $query->leftJoin("{$table} as {$alias}", "{$alias}.id", '=', $foreign);
    }
}
