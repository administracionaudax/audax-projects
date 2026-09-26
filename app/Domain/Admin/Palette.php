<?php

namespace App\Domain\Admin;

/**
 * Paleta de marca para departamentos, tipos de tarea y estados (SPEC §3.1, D-012): los colores
 * categóricos del tema claro más el gris secundario. Son los mismos que usan Department::DEFAULTS,
 * TaskType::DEFAULTS y TaskStatus::DEFAULTS. El nombre de cada color (para lectores de pantalla)
 * está en lang/ui/admin.json (admin.palette.*).
 */
final class Palette
{
    /**
     * @var list<string>
     */
    public const array COLORS = [
        '#0171FF', // azul
        '#179FA5', // turquesa
        '#5E2DAD', // violeta
        '#E65FB3', // magenta
        '#3C41AE', // índigo
        '#0892C4', // celeste
        '#56667A', // gris
    ];

    /**
     * Colores admitidos al guardar: los de la paleta y, al editar, el que ya tenía (p. ej. uno
     * anterior a la paleta), para no obligar a cambiarlo.
     *
     * @return list<string>
     */
    public static function allowed(?string $current = null): array
    {
        return $current !== null && ! in_array(strtoupper($current), self::COLORS, true)
            ? [...self::COLORS, strtoupper($current)]
            : self::COLORS;
    }
}
