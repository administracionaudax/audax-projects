<?php

namespace App\Domain\Billing\Holded;

use App\Domain\Reports\Money;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Lectura tolerante de los objetos de la API de Holded (Fase 12, F1; D-384). La referencia pública de
 * la v2 no detalla todos los campos (HOLDED-INVENTARIO, «Lo que no se pudo verificar»), así que cada
 * dato se busca por sus nombres de la v2 (`document_number`, `due_date`, `payments_pending`…) y, si
 * no, por los de la v1 (`docNumber`, `dueDate`, `paymentsPending`…). Las fechas pueden llegar en
 * ISO o en segundos Unix (v1); los importes, como cadenas decimales.
 */
final class HoldedPayload
{
    /**
     * @param  array<string, mixed>  $item
     */
    public static function id(array $item): ?string
    {
        $id = $item['id'] ?? $item['_id'] ?? null;

        return is_string($id) || is_int($id) ? (string) $id : null;
    }

    /**
     * El primer valor de texto no vacío de esas claves (admite «a.b» para anidados).
     *
     * @param  array<string, mixed>  $item
     */
    public static function string(array $item, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::get($item, $key);
            if (is_string($value) || is_int($value) || is_float($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Importe con 2 decimales (numeric-string) o null si no está.
     *
     * @param  array<string, mixed>  $item
     * @return numeric-string|null
     */
    public static function money(array $item, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::numeric(self::get($item, $key));
            if ($value !== null) {
                return Money::round(Money::of($value));
            }
        }

        return null;
    }

    /**
     * Número decimal con la escala pedida, o null.
     *
     * @param  array<string, mixed>  $item
     * @return numeric-string|null
     */
    public static function decimal(array $item, int $scale, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::numeric(self::get($item, $key));
            if ($value !== null) {
                return bcadd(Money::of($value), '0', $scale);
            }
        }

        return null;
    }

    /**
     * Número de Holded como texto con punto decimal. La v2 los da en formato español («1275,00»,
     * a veces «1.275,00»); también acepta números y texto con punto («1275.00»).
     *
     * @return numeric-string|int|float|null
     */
    public static function numeric(mixed $value): string|int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace([' ', "\u{00A0}", '€'], '', trim($value));
        if (str_contains($value, ',')) {
            // Coma decimal: los puntos son de miles.
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? $value : null;
    }

    /**
     * Fecha (día de Madrid) de una de esas claves: ISO, «Y-m-d» o segundos Unix.
     *
     * @param  array<string, mixed>  $item
     */
    public static function date(array $item, string ...$keys): ?CarbonImmutable
    {
        foreach ($keys as $key) {
            $value = self::get($item, $key);

            try {
                if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 9)) {
                    return CarbonImmutable::createFromTimestamp((int) $value, 'UTC')->setTimezone(LocalTime::timezone())->startOfDay();
                }
                if (is_string($value) && trim($value) !== '') {
                    $value = trim($value);

                    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                        ? CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalTime::timezone()) ?: null
                        : CarbonImmutable::parse($value)->setTimezone(LocalTime::timezone())->startOfDay();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function bool(array $item, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            $value = self::get($item, $key);
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) || (is_string($value) && in_array($value, ['0', '1', 'true', 'false'], true))) {
                return in_array($value, [1, '1', 'true'], true);
            }
        }

        return null;
    }

    /**
     * Lista de objetos (líneas, impuestos…).
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    public static function list(array $item, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = self::get($item, $key);
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [];
    }

    /**
     * Tipo de IVA de una línea: el número de `tax`/`tax_rate` o el de una clave «s_iva_21».
     *
     * @param  array<string, mixed>  $line
     * @return numeric-string|null
     */
    public static function taxRate(array $line): ?string
    {
        $rate = self::decimal($line, 2, 'tax_rate', 'tax', 'vat');
        if ($rate !== null) {
            return $rate;
        }

        $taxes = $line['taxes'] ?? null;
        foreach (is_array($taxes) ? $taxes : [] as $tax) {
            $key = is_array($tax) ? ($tax['key'] ?? $tax['id'] ?? null) : $tax;
            if (is_string($key) && preg_match('/iva_?(\d+(?:[._]\d+)?)/i', $key, $match) === 1) {
                return bcadd(Money::of(str_replace('_', '.', $match[1])), '0', 2);
            }
            if (is_array($tax) && isset($tax['rate']) && is_numeric($tax['rate'])) {
                return bcadd((string) $tax['rate'], '0', 2);
            }
        }

        return null;
    }

    /**
     * NIF sin espacios, guiones ni puntos, en mayúsculas y sin el prefijo «ES» del NIF-IVA español
     * (ESB26123456 → B26123456). Vacío → null.
     */
    public static function normalizeTaxId(?string $taxId): ?string
    {
        $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $taxId));

        if (strlen($value) === 11 && str_starts_with($value, 'ES')) {
            $value = substr($value, 2);
        }

        return $value === '' ? null : $value;
    }

    /**
     * Número de factura o código F comparable (Fase 12, D-388): mayúsculas y solo letras y cifras
     * («F26/0170» y «f260170» → «F260170»).
     */
    public static function normalizeNumber(?string $number): ?string
    {
        $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $number));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function get(array $item, string $key): mixed
    {
        if (array_key_exists($key, $item)) {
            return $item[$key];
        }

        $value = $item;
        foreach (explode('.', $key) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
