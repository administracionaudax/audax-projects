<?php

namespace App\Domain\Billing\Recurring;

use Carbon\CarbonImmutable;

/**
 * Las palabras dinámicas de las facturas recurrentes (PLAN-EMISION §3.1, H-060; E3): en las líneas,
 * el texto de la factura y la nota interna, se cambian al generar cada factura por las del periodo
 * que factura (la fecha que tocaba, no el día en que alguien pulsa «Generar ahora»).
 *
 * | Palabra | Octubre de 2026 |
 * |---|---|
 * | [mes] · [Mes] | octubre · Octubre |
 * | [mes anterior] · [Mes anterior] | septiembre · Septiembre |
 * | [mes siguiente] · [Mes siguiente] | noviembre · Noviembre |
 * | [año] | 2026 |
 * | [año anterior] · [año siguiente] | 2025 · 2027 |
 * | [año del mes anterior] · [año del mes siguiente] | 2026 · 2026 (en enero, el anterior es 2025) |
 * | [trimestre] · [trimestre anterior] · [trimestre siguiente] | 4T · 3T · 1T |
 * | [fecha] | 01/10/2026 |
 *
 * También sin espacios ([mesanterior]) y con mayúsculas o minúsculas; en mayúscula inicial
 * ([Mes]), el mes empieza por mayúscula. En inglés (cliente en inglés), los meses en inglés y el
 * trimestre como Q4. Lo que no es una palabra conocida se deja tal cual.
 */
final class DynamicWords
{
    private const array MONTHS_ES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const array MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** Las palabras que se reconocen (para la ayuda del editor). */
    public const array WORDS = ['[mes]', '[mes anterior]', '[mes siguiente]', '[año]', '[año anterior]', '[año siguiente]', '[año del mes anterior]', '[trimestre]', '[trimestre anterior]', '[fecha]'];

    public static function apply(?string $text, CarbonImmutable $period, string $language = 'es'): ?string
    {
        if ($text === null || ! str_contains($text, '[')) {
            return $text;
        }

        return (string) preg_replace_callback('/\[([^\[\]]{2,40})\]/u', function (array $match) use ($period, $language): string {
            $value = self::value($match[1], $period, $language);

            return $value ?? $match[0];
        }, $text);
    }

    /** El valor de una palabra (sin corchetes) o null si no es una de las conocidas. */
    private static function value(string $word, CarbonImmutable $period, string $language): ?string
    {
        $key = self::normalize($word);
        $capital = mb_substr(trim($word), 0, 1) !== mb_strtolower(mb_substr(trim($word), 0, 1));
        $month = $period->startOfMonth();

        $value = match ($key) {
            'mes' => self::month($month, $language),
            'mesanterior' => self::month($month->subMonthNoOverflow(), $language),
            'messiguiente' => self::month($month->addMonthNoOverflow(), $language),
            'ano' => (string) $month->year,
            'anoanterior' => (string) ($month->year - 1),
            'anosiguiente' => (string) ($month->year + 1),
            'anodelmesanterior' => (string) $month->subMonthNoOverflow()->year,
            'anodelmessiguiente' => (string) $month->addMonthNoOverflow()->year,
            'trimestre' => self::quarter($month, $language),
            'trimestreanterior' => self::quarter($month->subMonthsNoOverflow(3), $language),
            'trimestresiguiente' => self::quarter($month->addMonthsNoOverflow(3), $language),
            'fecha' => $period->format('d/m/Y'),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        return $capital ? mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1) : $value;
    }

    private static function month(CarbonImmutable $date, string $language): string
    {
        return $language === 'en' ? self::MONTHS_EN[$date->month - 1] : self::MONTHS_ES[$date->month - 1];
    }

    private static function quarter(CarbonImmutable $date, string $language): string
    {
        return $language === 'en' ? 'Q'.$date->quarter : $date->quarter.'T';
    }

    /** «Mes anterior» → «mesanterior»; «año» → «ano» (sin tildes, espacios ni mayúsculas). */
    private static function normalize(string $word): string
    {
        $word = mb_strtolower(trim($word));
        $word = strtr($word, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/[\s_-]+/u', '', $word);
    }
}
