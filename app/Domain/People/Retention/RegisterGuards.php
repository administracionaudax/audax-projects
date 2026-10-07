<?php

namespace App\Domain\People\Retention;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Los *triggers* que impiden borrar el registro de jornada (D-332 y D-348) y la única puerta para
 * hacerlo: la supresión pasados 48 meses de `app:prune-data` (RegisterPruner).
 *
 * - **PostgreSQL** (el servidor y la CI): las funciones de los *triggers* rechazan DELETE salvo que
 *   la transacción haya puesto `SET LOCAL audax.register_prune = 'on'`. Solo lo pone
 *   withPruning(), dentro de su transacción; al acabar, el ajuste desaparece solo. UPDATE y
 *   TRUNCATE siguen prohibidos siempre.
 * - **SQLite** (los tests en el Mac): un *trigger* no puede leer una variable de sesión, así que
 *   withPruning() quita los de DELETE dentro de su transacción y los vuelve a crear antes de
 *   confirmarla.
 *
 * Quien tenga acceso a la base de datos podría hacer lo mismo, pero la cadena de huellas, los
 * puntos de control y el ancla diaria lo delatarían (RegisterIntegrity).
 */
final class RegisterGuards
{
    /** Ajuste de la transacción que permite borrar en PostgreSQL. */
    public const string PRUNE_SETTING = 'audax.register_prune';

    /**
     * Tablas del registro que no admiten DELETE (salvo la supresión) → nombre de su *trigger* de
     * DELETE en SQLite.
     */
    public const array SQLITE_DELETE_TRIGGERS = [
        'clock_events' => 'clock_events_no_delete',
        'clock_corrections' => 'clock_corrections_no_delete',
        'month_closes' => 'month_closes_no_delete',
        'overtime_decisions' => 'overtime_decisions_no_delete',
        'time_balance_movements' => 'time_balance_movements_no_delete',
        'register_anchors' => 'register_anchors_no_delete',
    ];

    /**
     * Ejecuta $prune con el borrado del registro permitido, en una sola transacción.
     *
     * @template T
     *
     * @param  Closure(): T  $prune
     * @return T
     */
    public static function withPruning(Closure $prune): mixed
    {
        return DB::transaction(function () use ($prune): mixed {
            $driver = DB::getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('SET LOCAL '.self::PRUNE_SETTING." = 'on'");

                try {
                    return $prune();
                } finally {
                    // Dentro de otra transacción (los tests), SET LOCAL duraría hasta su final.
                    DB::statement('SET LOCAL '.self::PRUNE_SETTING." = 'off'");
                }
            }

            if ($driver === 'sqlite') {
                foreach (self::SQLITE_DELETE_TRIGGERS as $trigger) {
                    DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
                }

                try {
                    return $prune();
                } finally {
                    self::installSqliteDeleteTriggers();
                }
            }

            return $prune();
        });
    }

    /** Los *triggers* de SQLite que rechazan DELETE en las tablas del registro. */
    public static function installSqliteDeleteTriggers(): void
    {
        foreach (self::SQLITE_DELETE_TRIGGERS as $table => $trigger) {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$trigger} BEFORE DELETE ON {$table} "
                ."BEGIN SELECT RAISE(ABORT, '{$table}: el registro de jornada no se borra (art. 34.9 ET)'); END;");
        }
    }

    /** Expresión de PL/pgSQL: ¿esta transacción es la supresión de app:prune-data? */
    public const string PG_PRUNING = "coalesce(current_setting('audax.register_prune', true), '') = 'on'";
}
