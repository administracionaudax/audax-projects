<?php

namespace App\Domain\Absences;

/**
 * Reparto de lo que se gasta entre lo que hay (Fase 11, R3; W-061 y W-062; D-363). Sin base de
 * datos, para poder probarlo a fondo.
 *
 * - **Abonos** («lotes»): cada movimiento positivo del libro, con desde cuándo vale y cuándo caduca.
 * - **Cargos**: cada día de una ausencia (aprobada o pendiente) con su coste, y los movimientos
 *   negativos (ajustes o recálculos), con su fecha.
 * - Un cargo del día D solo puede tirar de abonos que valen ese día (desde ≤ D ≤ caducidad) y
 *   **gasta primero lo que caduca antes** (y, a igualdad, el más antiguo). Un movimiento negativo
 *   gasta primero de los abonos de su mismo año.
 * - Primero se reparten las aprobadas y los movimientos, por fecha; después las pendientes, con lo
 *   que queda (reservan, pero no quitan a lo ya aprobado).
 * - Lo que no cabe queda **al descubierto** (saldo negativo): se ve, pero nunca se tapa.
 *
 * @phpstan-type Lot array{id: int, year: int, amount: int, valid_from: string, expires_on: string|null, kind: string}
 * @phpstan-type Debit array{date: string, amount: int, pending: bool, year?: int|null, ref?: string}
 * @phpstan-type LotState array{id: int, year: int, amount: int, valid_from: string, expires_on: string|null, kind: string, used: int, reserved: int, remaining: int}
 * @phpstan-type Uncovered array{date: string, amount: int, pending: bool, ref: string|null}
 */
final class LeaveAllocator
{
    /**
     * @param  list<Lot>  $lots
     * @param  list<Debit>  $debits
     * @return array{lots: list<LotState>, uncovered: list<Uncovered>}
     */
    public static function allocate(array $lots, array $debits): array
    {
        $state = array_map(fn (array $lot): array => [...$lot, 'used' => 0, 'reserved' => 0, 'remaining' => $lot['amount']], $lots);

        // Orden de gasto: caduca antes; sin caducidad, al final; después, el más antiguo.
        usort($state, fn (array $a, array $b): int => [$a['expires_on'] ?? '9999-12-31', $a['valid_from'], $a['id']] <=> [$b['expires_on'] ?? '9999-12-31', $b['valid_from'], $b['id']]);

        $firm = array_values(array_filter($debits, fn (array $debit): bool => ! $debit['pending']));
        $pending = array_values(array_filter($debits, fn (array $debit): bool => $debit['pending']));
        $byDate = fn (array $a, array $b): int => [$a['date'], $a['ref'] ?? ''] <=> [$b['date'], $b['ref'] ?? ''];
        usort($firm, $byDate);
        usort($pending, $byDate);

        $uncovered = [];

        foreach ([...$firm, ...$pending] as $debit) {
            $left = $debit['amount'];
            $field = $debit['pending'] ? 'reserved' : 'used';
            $year = $debit['year'] ?? null;

            // Un movimiento negativo de un año gasta primero de ese año.
            $passes = $year === null ? [null] : [$year, null];

            foreach ($passes as $only) {
                foreach ($state as $index => $lot) {
                    if ($left <= 0) {
                        break 2;
                    }

                    if ($only !== null && $lot['year'] !== $only) {
                        continue;
                    }

                    if ($lot['remaining'] <= 0 || $lot['valid_from'] > $debit['date'] || ($lot['expires_on'] !== null && $lot['expires_on'] < $debit['date'])) {
                        continue;
                    }

                    $take = min($lot['remaining'], $left);
                    $state[$index][$field] += $take;
                    $state[$index]['remaining'] -= $take;
                    $left -= $take;
                }
            }

            if ($left > 0) {
                $uncovered[] = ['date' => $debit['date'], 'amount' => $left, 'pending' => $debit['pending'], 'ref' => $debit['ref'] ?? null];
            }
        }

        return ['lots' => $state, 'uncovered' => $uncovered];
    }
}
