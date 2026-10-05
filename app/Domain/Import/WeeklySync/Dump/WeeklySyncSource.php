<?php

namespace App\Domain\Import\WeeklySync\Dump;

/**
 * Origen de las tablas de WeeklySync para el volcado: la base de Supabase en solo lectura
 * (PostgresWeeklySyncSource) o, en los tests, un doble en memoria.
 */
interface WeeklySyncSource
{
    /**
     * ¿Existe la tabla en el origen? (Un esquema antiguo puede no tener alguna.)
     */
    public function has(string $table): bool;

    /**
     * Filas de una tabla de `public`, ordenadas por id, como arrays (JSON de to_jsonb).
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(string $table): iterable;

    /**
     * Termina la lectura (cierra la transacción de solo lectura).
     */
    public function close(): void;
}
