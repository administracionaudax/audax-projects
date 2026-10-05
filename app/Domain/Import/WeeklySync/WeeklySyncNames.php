<?php

namespace App\Domain\Import\WeeklySync;

use Illuminate\Support\Str;

/**
 * Normalización para casar personas y clientes (WEEKLY-INVENTARIO D.3).
 */
final class WeeklySyncNames
{
    /** Formas societarias que se quitan al final del nombre (tras quitar puntos y signos). */
    private const array LEGAL_SUFFIXES = ['sl', 'slu', 'sll', 'slp', 'sa', 'sau', 'scoop', 'sc', 'cb'];

    public static function email(mixed $value): string
    {
        return is_string($value) ? Str::lower(trim($value)) : '';
    }

    /**
     * Nombre de cliente normalizado: sin tildes ni emoji, en minúsculas, sin signos y sin la forma
     * societaria del final («Manzanas Pérez, S.L.» → «manzanas perez»).
     */
    public static function client(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));
        $name = str_replace('.', '', $name);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
        // «s l», «s a u»… (con espacios tras quitar los signos) → «sl», «sau».
        $name = (string) preg_replace('/\b(s) (l|a|c)(?: (u|l|p))?$/', '$1$2$3', $name);

        $words = $name === '' ? [] : explode(' ', $name);

        while (count($words) > 1 && in_array(end($words), self::LEGAL_SUFFIXES, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /**
     * ¿Se parecen dos nombres normalizados? (uno contiene al otro o empiezan por la misma palabra
     * de más de tres letras). Solo como confirmación de una señal fuerte (códigos o facturas).
     */
    public static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        $first = explode(' ', $a)[0];

        return strlen($first) > 3 && $first === explode(' ', $b)[0];
    }
}
