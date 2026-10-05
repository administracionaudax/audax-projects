<?php

namespace App\Domain\Weeklies;

use App\Domain\Weeklies\Satisfaction\SatisfactionDecision;
use App\Domain\Weeklies\Satisfaction\SignalMetrics;

/**
 * Regla determinista que amortigua el delta de satisfacción que propone Gemini al cerrar la weekly
 * (D-146, F-093). Port EXACTO de `ws:supabase/functions/_shared/satisfaction.js` (líneas 1-221),
 * comprobado con los fixtures compartidos tests/fixtures/weeklies/satisfaction-cases.json, que se
 * generan ejecutando el JS original con node (tests/fixtures/weeklies/generate-satisfaction-cases.mjs).
 *
 * Para ser exacto imita la semántica de JavaScript que usa el original:
 * - Number() (null → 0, '' → 0, ' 3 ' → 3, '0x10' → 16, 'abc' → NaN, ausente → NaN),
 * - la veracidad (|| y !): '', 0, null y ausente son falsos; '0' y [] son verdaderos,
 * - Math.round (redondea .5 hacia +∞), trim() y \s con los espacios Unicode de JS,
 * - las comparaciones con null (null ≥ 92 es falso; null ≤ 8 es verdadero, porque null → 0).
 * En las entradas, una clave AUSENTE equivale a `undefined` en JS; null es null.
 *
 * Lógica pura: sin base de datos ni IA.
 */
final class SatisfactionStabilizer
{
    public const array POSITIVE_KEYWORDS = [
        'logro', 'avance', 'completado', 'cumplido', 'mejora', 'mejorado', 'éxito', 'exito',
        'positivo', 'satisfecho', 'satisfacción', 'satisfaccion', 'fluido', 'resuelto', 'resuelta',
        'entregado', 'entrega exitosa', 'sin bloqueos', 'excelente', 'progreso', 'hito',
    ];

    public const array NEGATIVE_KEYWORDS = [
        'bloqueo', 'bloqueado', 'retraso', 'incidencia', 'problema', 'riesgo', 'riesgos', 'queja',
        'insatisfacción', 'insatisfaccion', 'pendiente crítico', 'pendiente critico', 'escalado',
        'fallo', 'error', 'demora', 'atasco', 'cancelación', 'cancelacion', 'preocupado', 'preocupación', 'preocupacion',
    ];

    public const array ROUTINE_KEYWORDS = [
        'seguimos', 'continuamos', 'seguimiento', 'reunión', 'reunion', 'revisión', 'revision',
        'mantenimiento', 'soporte', 'documentación', 'documentacion', 'tareas habituales', 'sin cambios',
        'trabajo habitual', 'día a día', 'dia a dia',
    ];

    public const array EXPLICIT_POSITIVE_CLIENT_KEYWORDS = [
        'cliente contento', 'cliente satisfecho', 'muy contento', 'muy satisfecho', 'agradeció',
        'agradecio', 'felicitó', 'felicito', 'dio el ok', 'aprobó', 'aprobo', 'validó', 'valido',
        'feedback positivo', 'buena valoración', 'buena valoracion',
    ];

    public const array EXPLICIT_NEGATIVE_CLIENT_KEYWORDS = [
        'cliente molesto', 'cliente preocupado', 'cliente descontento', 'cliente insatisfecho',
        'queja', 'quejas', 'feedback negativo', 'escaló', 'escalo', 'rechazó', 'rechazo',
        'no está contento', 'no esta contento', 'malestar',
    ];

    public const array SEVERE_NEGATIVE_KEYWORDS = [
        'bloqueo total', 'bloqueado', 'parado', 'producción caída', 'produccion caida',
        'error crítico', 'error critico', 'incidencia grave', 'deadline incumplido',
        'entrega fallida', 'cancelación', 'cancelacion',
    ];

    public const array DELIVERED_VALUE_KEYWORDS = [
        'entregado', 'entrega', 'lanzado', 'publicado', 'cerrado', 'resuelto', 'resuelta',
        'aprobado', 'aprobada', 'hito completado', 'objetivo cumplido',
    ];

    /** Espacios en blanco y fines de línea de JavaScript (\s y String.prototype.trim). */
    private const string JS_SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * stabilizeSatisfactionDelta(). Claves: requestedDelta, currentSatisfaction, reportText,
     * evidenceLevel, explicitClientImpact y confidence. Una clave ausente es `undefined`.
     *
     * @param  array<string, mixed>  $input
     */
    public function stabilize(array $input): SatisfactionDecision
    {
        $requested = self::jsNumber($input, 'requestedDelta');
        $reportText = $input['reportText'] ?? null;
        $current = array_key_exists('currentSatisfaction', $input) ? $input['currentSatisfaction'] : NAN;
        $explicitImpact = self::jsTruthy($input, 'explicitClientImpact');

        if (! is_finite($requested) || $requested == 0) {
            return new SatisfactionDecision(0, 'zero-or-invalid-request', $this->signalMetrics($reportText));
        }

        $finalDelta = (int) self::clamp(self::jsRound($requested), -8, 8);

        $metrics = $this->signalMetrics($reportText);
        $evidence = $this->normalizeEvidenceLevel($input['evidenceLevel'] ?? null, array_key_exists('evidenceLevel', $input));
        $confidence = $this->normalizeConfidence($input, 'confidence');
        $direction = $finalDelta <=> 0;
        $isPositiveChange = $direction > 0;
        $hasDirectionalSignal = $isPositiveChange ? $metrics->hasPositiveChangeSignal() : $metrics->hasNegativeChangeSignal();

        if ($metrics->wordCount < 12) {
            return new SatisfactionDecision(0, 'too-little-information', $metrics);
        }

        if (! $hasDirectionalSignal && ! $explicitImpact) {
            return new SatisfactionDecision(0, 'no-explicit-client-impact', $metrics);
        }

        if ($metrics->routineSignals >= 2 && ! $metrics->hasExplicitSignal() && $metrics->severeNegativeSignals === 0) {
            return new SatisfactionDecision(0, 'routine-week-no-change', $metrics);
        }

        $max = match ($evidence) {
            'LOW' => 2,
            'MEDIUM' => 4,
            'HIGH' => 6,
            default => 0,
        };

        if (! $explicitImpact && ! $metrics->hasExplicitSignal()) {
            $max = min($max, 2);
        }

        if ($metrics->hasMixedSignals()) {
            $max = min($max, 2);
        }

        if ($confidence !== null && $confidence < 0.5) {
            $max = max(0, $max - 2);
        } elseif ($confidence !== null && $confidence < 0.65) {
            $max = max(0, $max - 1);
        }

        $max = $this->capNearEdges($max, $isPositiveChange, $current);

        if ($isPositiveChange && ! $metrics->hasPositiveChangeSignal() && $metrics->balance() <= 1) {
            $max = 0;
        }

        if (! $isPositiveChange && ! $metrics->hasNegativeChangeSignal() && $metrics->balance() >= -1) {
            $max = 0;
        }

        $tone = $metrics->balance();
        if ($tone >= 2 && $finalDelta < 0) {
            $finalDelta = 1;
        } elseif ($tone <= -2 && $finalDelta > 0) {
            $finalDelta = -1;
        }

        if ($evidence === 'HIGH' && $explicitImpact && (
            $metrics->explicitPositiveSignals > 0
            || $metrics->explicitNegativeSignals > 0
            || $metrics->severeNegativeSignals > 0
            || $metrics->deliveredValueSignals >= 2
        )) {
            $max = max($max, 8);
        } elseif ($evidence === 'MEDIUM' && ($explicitImpact || $metrics->hasExplicitSignal() || abs($metrics->balance()) >= 2)) {
            $max = max($max, 4);
        }

        $max = $this->capNearEdges($max, $isPositiveChange, $current);

        if ($max <= 0) {
            return new SatisfactionDecision(0, 'guarded-to-stable', $metrics);
        }

        $guarded = min(abs($finalDelta), $max);

        return new SatisfactionDecision(
            $direction * $guarded,
            $max < abs($finalDelta) ? 'dampened' : 'accepted',
            $metrics,
        );
    }

    /**
     * getSignalMetrics().
     */
    public function signalMetrics(mixed $text): SignalMetrics
    {
        $normalized = self::jsTrim(self::jsTruthyValue($text) ? self::jsString($text) : '');
        $words = $normalized === ''
            ? 0
            : count(array_filter(preg_split('/['.self::JS_SPACE.']+/u', $normalized) ?: [], fn (string $word): bool => $word !== ''));

        return new SignalMetrics(
            wordCount: $words,
            positiveSignals: $this->countMatches($normalized, self::POSITIVE_KEYWORDS),
            negativeSignals: $this->countMatches($normalized, self::NEGATIVE_KEYWORDS),
            routineSignals: $this->countMatches($normalized, self::ROUTINE_KEYWORDS),
            explicitPositiveSignals: $this->countMatches($normalized, self::EXPLICIT_POSITIVE_CLIENT_KEYWORDS),
            explicitNegativeSignals: $this->countMatches($normalized, self::EXPLICIT_NEGATIVE_CLIENT_KEYWORDS),
            severeNegativeSignals: $this->countMatches($normalized, self::SEVERE_NEGATIVE_KEYWORDS),
            deliveredValueSignals: $this->countMatches($normalized, self::DELIVERED_VALUE_KEYWORDS),
        );
    }

    /**
     * getToneScore(): señales positivas menos negativas.
     */
    public function toneScore(mixed $text): int
    {
        $text = self::jsTruthyValue($text) ? self::jsString($text) : '';

        return $this->countMatches($text, self::POSITIVE_KEYWORDS) - $this->countMatches($text, self::NEGATIVE_KEYWORDS);
    }

    /**
     * countMatches(): cuántos términos aparecen (una vez cada uno) en el texto en minúsculas.
     *
     * @param  list<string>  $terms
     */
    public function countMatches(string $text, array $terms): int
    {
        $lowered = mb_strtolower($text, 'UTF-8');
        $count = 0;

        foreach ($terms as $term) {
            if (str_contains($lowered, $term)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * normalizeEvidenceLevel(): HIGH, MEDIUM, LOW o NONE.
     */
    public function normalizeEvidenceLevel(mixed $value, bool $defined = true): string
    {
        $normalized = mb_strtoupper(self::jsTrim($defined && self::jsTruthyValue($value) ? self::jsString($value) : ''), 'UTF-8');

        return in_array($normalized, ['HIGH', 'MEDIUM', 'LOW'], true) ? $normalized : 'NONE';
    }

    /**
     * normalizeConfidence(): un número entre 0 y 1, o null si no es un número finito.
     *
     * @param  array<string, mixed>  $input
     */
    public function normalizeConfidence(array $input, string $key): ?float
    {
        $value = self::jsNumber($input, $key);

        return is_finite($value) ? (float) self::clamp($value, 0, 1) : null;
    }

    /**
     * getStableReasoning(): el motivo de Gemini con la coletilla de estabilidad o amortiguación.
     */
    public function stableReasoning(mixed $baseReasoning, int $finalDelta, string $rule): string
    {
        $reasoning = self::jsTrim(self::jsTruthyValue($baseReasoning) ? self::jsString($baseReasoning) : '');

        if ($finalDelta === 0) {
            return $reasoning !== ''
                ? "{$reasoning} La señal observada no justifica mover el índice esta semana."
                : 'La información disponible no justifica cambiar el índice de satisfacción esta semana.';
        }

        if ($rule === 'dampened' && $reasoning !== '') {
            return "{$reasoning} El ajuste final se ha amortiguado para evitar oscilaciones excesivas con una sola weekly.";
        }

        return $reasoning;
    }

    /**
     * cleanJsonResponse(): quita las vallas ``` de una respuesta JSON de la IA.
     */
    public static function cleanJsonResponse(mixed $value): string
    {
        $clean = self::jsTrim(self::jsTruthyValue($value) ? self::jsString($value) : '');

        if (str_starts_with($clean, '```json')) {
            $clean = (string) preg_replace('/```json\n?/', '', $clean);
            $clean = (string) preg_replace('/```\n?/', '', $clean);
        } elseif (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/```\n?/', '', $clean);
        }

        return self::jsTrim($clean);
    }

    /** Topes cerca de los extremos: desde 82 y 92 se frena la subida; hasta 18 y 8, la bajada. */
    private function capNearEdges(int $max, bool $isPositiveChange, mixed $current): int
    {
        if ($isPositiveChange && self::jsGreaterOrEqual($current, 92)) {
            return min($max, 1);
        }

        if ($isPositiveChange && self::jsGreaterOrEqual($current, 82)) {
            return min($max, 2);
        }

        if (! $isPositiveChange && self::jsLessOrEqual($current, 8)) {
            return min($max, 1);
        }

        if (! $isPositiveChange && self::jsLessOrEqual($current, 18)) {
            return min($max, 2);
        }

        return $max;
    }

    private static function clamp(float|int $value, int $min, int $max): float|int
    {
        return max($min, min($max, $value));
    }

    /** Math.round(): redondea .5 hacia +∞. */
    private static function jsRound(float $value): float
    {
        return floor($value + 0.5);
    }

    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^['.self::JS_SPACE.']+|['.self::JS_SPACE.']+$/u', '', $value);
    }

    /** String(value) para escalares (los objetos no llegan aquí). */
    private static function jsString(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) && floor($value) === $value && abs($value) < 1e21 => (string) (int) $value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** Veracidad de JavaScript de un valor presente. */
    private static function jsTruthyValue(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_float($value) => $value != 0 && ! is_nan($value),
            is_string($value) => $value !== '',
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function jsTruthy(array $input, string $key): bool
    {
        return array_key_exists($key, $input) && self::jsTruthyValue($input[$key]);
    }

    /**
     * Number(value) de JavaScript; NAN si la clave no está (undefined).
     *
     * @param  array<string, mixed>  $input
     */
    private static function jsNumber(array $input, string $key): float
    {
        return array_key_exists($key, $input) ? self::toNumber($input[$key]) : NAN;
    }

    private static function toNumber(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return match (count($value)) {
                0 => 0.0,
                1 => self::toNumber(self::jsString(array_values($value)[0])),
                default => NAN,
            };
        }

        if (! is_string($value)) {
            return NAN;
        }

        $text = self::jsTrim($value);

        return match (true) {
            $text === '' => 0.0,
            preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text) === 1 => (float) $text,
            preg_match('/^([+-]?)Infinity$/', $text, $match) === 1 => $match[1] === '-' ? -INF : INF,
            preg_match('/^0[xX]([0-9a-fA-F]+)$/', $text, $match) === 1 => (float) hexdec($match[1]),
            preg_match('/^0[oO]([0-7]+)$/', $text, $match) === 1 => (float) octdec($match[1]),
            preg_match('/^0[bB]([01]+)$/', $text, $match) === 1 => (float) bindec($match[1]),
            default => NAN,
        };
    }

    /** a >= b de JavaScript con un número: null cuenta como 0; lo que no es número, falso. */
    private static function jsGreaterOrEqual(mixed $value, int $than): bool
    {
        $number = self::relational($value);

        return ! is_nan($number) && $number >= $than;
    }

    private static function jsLessOrEqual(mixed $value, int $than): bool
    {
        $number = self::relational($value);

        return ! is_nan($number) && $number <= $than;
    }

    private static function relational(mixed $value): float
    {
        return is_float($value) && is_nan($value) ? NAN : self::toNumber($value);
    }
}
