<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Support\Duration;
use Carbon\CarbonImmutable;

/**
 * Textos de una ausencia en mensajes, avisos y emails (lang/es/absences.php): el tipo y sus fechas
 * («Vacaciones del 05/10/2026 al 09/10/2026», «un permiso el 05/10/2026 (2:00)»).
 */
final class AbsenceText
{
    /**
     * «del 05/10/2026 al 09/10/2026», «el 05/10/2026» o «el 05/10/2026 (2:00)».
     */
    public static function period(string $start, string $end, ?int $partialMinutes = null): string
    {
        $from = CarbonImmutable::parse($start)->format('d/m/Y');

        if ($partialMinutes !== null) {
            return self::get('absences.period.partial', ['date' => $from, 'minutes' => Duration::format($partialMinutes)]);
        }

        if ($start === $end) {
            return self::get('absences.period.day', ['date' => $from]);
        }

        return self::get('absences.period.range', ['from' => $from, 'to' => CarbonImmutable::parse($end)->format('d/m/Y')]);
    }

    /**
     * El tipo dentro de una frase: «vacaciones», «una baja», «un permiso»…
     */
    public static function phrase(AbsenceType $type): string
    {
        return self::get("absences.type_phrases.{$type->value}");
    }

    public static function status(AbsenceStatus $status): string
    {
        return mb_strtolower($status->label());
    }

    /**
     * Una línea de un email en Markdown con textos que escribe la gente (notas, comentarios,
     * nombres): se escapan los corchetes (sin ellos no hay enlaces ni imágenes: «[Firma aquí](https://…)»
     * llega como texto), el énfasis y el código; al principio, un título o una lista. Los saltos de
     * línea pasan a espacios: nada de bloques nuevos. El HTML ya lo escapa la plantilla de Laravel.
     * Solo para el email: la campana pinta texto plano.
     */
    public static function markdown(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/[\\\\`*_\[\]]/', '\\\\$0', $text);

        return (string) preg_replace(['/^(\d+)([.)])/', '/^([-+#])/'], ['$1\\\\$2', '\\\\$1'], $text);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice($key, $count, ['count' => $count, ...$replace]);
    }
}
