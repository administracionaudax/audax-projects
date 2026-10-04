<?php

namespace App\Support;

/**
 * Lectura tolerante de los filtros de la URL (Mis tareas y el calendario del equipo, D-143 y
 * D-144): lo que no vale se ignora, nunca da un error. Las listas van separadas por comas
 * (?proyecto=3,7) o como array (?proyecto[]=3&proyecto[]=7).
 */
final class QueryParams
{
    /** Como mucho estos ids por filtro: lo que pase se ignora. */
    public const int MAX_IDS = 100;

    /** Longitud máxima del texto de búsqueda. */
    public const int MAX_TEXT = 100;

    /**
     * Ids positivos, sin repetir y ordenados.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        $parts = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => array_values($value),
            default => [],
        };

        $ids = [];

        foreach ($parts as $part) {
            if (is_string($part) || is_int($part)) {
                $id = self::id((string) $part);

                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }

            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        sort($ids);

        return $ids;
    }

    public static function id(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 18 && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * «1», «true», «si» o «sí» (y el booleano de un formulario) → true.
     */
    public static function flag(mixed $value): bool
    {
        return is_string($value) && in_array(mb_strtolower(trim($value)), ['1', 'true', 'si', 'sí', 'on'], true);
    }

    /**
     * Texto recortado (sin espacios a los lados y como mucho MAX_TEXT caracteres), o null.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT);
    }

    /**
     * Fecha AAAA-MM-DD válida entre 2000 y 2100 (como el calendario de tareas), o null.
     */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        [, $year, $month, $day] = array_map(intval(...), $parts);

        return checkdate($month, $day, $year) && $year >= 2000 && $year <= 2100 ? $value : null;
    }

    /**
     * Uno de los valores permitidos (clave de la URL → valor interno), o null.
     *
     * @template T
     *
     * @param  array<string, T>  $allowed
     * @return T|null
     */
    public static function choice(mixed $value, array $allowed): mixed
    {
        return is_string($value) && array_key_exists($value, $allowed) ? $allowed[$value] : null;
    }
}
