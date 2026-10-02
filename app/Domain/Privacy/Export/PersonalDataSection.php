<?php

namespace App\Domain\Privacy\Export;

use App\Models\User;

/**
 * Una sección del ZIP de datos personales (D-075): un fichero JSON y otro CSV con las mismas
 * filas. Las secciones se registran en config/privacy.php (export_sections); al integrar la
 * Fase 6 se añade la de los mensajes del chat sin tocar nada más.
 *
 * Reglas: solo datos de la propia persona; nunca contraseñas, secretos del doble factor ni tokens;
 * sin datos económicos de la empresa (costes y tarifas, SPEC §5). Los instantes van en ISO 8601 con
 * la hora de Madrid y las fechas como AAAA-MM-DD.
 */
interface PersonalDataSection
{
    /** Nombre de los ficheros, sin extensión (p. ej. «horas» → horas.json y horas.csv). */
    public function key(): string;

    /** Qué contiene, para LEEME.txt. */
    public function description(): string;

    /**
     * Columnas: clave de cada fila (JSON) → cabecera legible (CSV).
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * Filas de la persona, con las claves de columns().
     *
     * @return iterable<array<string, string|int|bool|null>>
     */
    public function rows(User $user): iterable;
}
