<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Coste estimado en USD de una llamada (F-173), con BCMath y 6 decimales (nunca float). Precios por
 * millón de tokens o de caracteres en config/services.php (gemini.pricing y google_tts.price_per_million_chars),
 * como `ws:_shared/aiTelemetry.ts`. Un modelo sin precio conocido da null.
 */
final class AiPricing
{
    public static function gemini(string $model, ?int $promptTokens, ?int $responseTokens): ?string
    {
        /** @var array<string, array{input: int|float|string, output: int|float|string}> $pricing */
        $pricing = (array) config('services.gemini.pricing', []);
        $price = $pricing[$model] ?? null;

        if ($price === null) {
            return null;
        }

        $input = bcmul((string) ($promptTokens ?? 0), self::decimal($price['input']), 10);
        $output = bcmul((string) ($responseTokens ?? 0), self::decimal($price['output']), 10);

        return self::round(bcdiv(bcadd($input, $output, 10), '1000000', 10));
    }

    public static function speech(?int $characters): ?string
    {
        $price = config('services.google_tts.price_per_million_chars');

        if (! is_numeric($price) || ! $characters) {
            return null;
        }

        return self::round(bcdiv(bcmul((string) $characters, self::decimal($price), 10), '1000000', 10));
    }

    /**
     * Redondeo a 6 decimales, la mitad hacia arriba (toFixed(6) del original).
     *
     * @param  numeric-string  $value
     */
    private static function round(string $value): string
    {
        return bcadd($value, '0.0000005', 6);
    }

    /**
     * Precio de la configuración como número decimal en texto (un valor que no es un número, 0).
     *
     * @return numeric-string
     */
    private static function decimal(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 10, '.', '') : (is_int($value) || is_string($value) ? (string) $value : '');

        return is_numeric($text) ? $text : '0';
    }
}
