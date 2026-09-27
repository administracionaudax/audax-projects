<?php

namespace App\Domain\Reports\Export;

use Generator;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Recorre entradas por bloques con paginación por clave (PERF-03): en orden de fecha e id, y cada
 * bloque empieza justo después de la última fila del anterior (no con OFFSET). Una entrada que se
 * crea o se borra entre dos bloques no desplaza las demás: ninguna sale dos veces ni se pierde.
 * La consulta debe traer time_entries.date y time_entries.id como «date» e «id».
 */
final class KeysetPages
{
    /**
     * @return Generator<int, stdClass>
     */
    public static function byDateAndId(Builder $query, int $size): Generator
    {
        $size = max(1, $size);
        $date = null;
        $id = null;

        do {
            $page = (clone $query)
                ->when($date !== null, fn (Builder $after) => $after->where(fn (Builder $key) => $key
                    ->where('time_entries.date', '>', $date)
                    ->orWhere(fn (Builder $same) => $same->where('time_entries.date', '=', $date)->where('time_entries.id', '>', $id))))
                ->reorder('time_entries.date')
                ->orderBy('time_entries.id')
                ->limit($size)
                ->get();

            foreach ($page as $row) {
                yield $row;
            }

            $last = $page->last();
            if ($last instanceof stdClass) {
                $date = $last->date ?? null;
                $id = $last->id ?? null;
            }
        } while ($page->count() === $size && $date !== null);
    }
}
