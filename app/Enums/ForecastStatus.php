<?php

namespace App\Enums;

/**
 * Estado de un proyecto previsto (docs/PLAN-CARGAS.md §5.2 y §6.5, D-281):
 * - abierto: se está decidiendo (segura o posible),
 * - confirmado: se ha ganado (siempre «segura») y aún no hay proyecto real,
 * - perdido: con motivo; deja de contar,
 * - vinculado: ya tiene proyecto real; sus asignaciones quedan congeladas como línea base y no
 *   cuentan en la carga (ya cuentan las del real).
 */
enum ForecastStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Lost = 'lost';
    case Linked = 'linked';

    public function label(): string
    {
        return __("forecast.enums.status.{$this->value}");
    }

    /** ¿Cuentan sus asignaciones en la previsión? Abierto o confirmado. */
    public function counts(): bool
    {
        return $this === self::Open || $this === self::Confirmed;
    }

    /** ¿Se pueden editar el previsto y sus asignaciones? Solo mientras cuenta. */
    public function isEditable(): bool
    {
        return $this->counts();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
