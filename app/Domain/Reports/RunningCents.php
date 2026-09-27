<?php

namespace App\Domain\Reports;

/**
 * Reparto de céntimos en streaming (Cents::running) para una columna de importes: cada línea es
 * lo acumulado exacto redondeado menos lo ya repartido. Las líneas suman siempre el acumulado
 * redondeado una vez y ninguna se aleja más de un céntimo de su importe exacto. Para exportar
 * varias columnas de importes a la vez (ingreso y coste), una instancia por columna.
 */
final class RunningCents
{
    /** @var numeric-string */
    private string $sum = '0';

    /** @var numeric-string */
    private string $shown = '0.00';

    /**
     * El importe de la siguiente línea (2 decimales), o null si la línea no lleva importe.
     *
     * @return numeric-string|null
     */
    public function next(?string $exact): ?string
    {
        if ($exact === null) {
            return null;
        }

        $this->sum = Money::add($this->sum, $exact);
        $upTo = Money::round($this->sum);
        $line = bcsub($upTo, $this->shown, 2);
        $this->shown = $upTo;

        return $line;
    }

    /**
     * Lo repartido hasta ahora: la suma de las líneas y el acumulado exacto redondeado.
     *
     * @return numeric-string
     */
    public function total(): string
    {
        return $this->shown;
    }
}
