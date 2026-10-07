<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Collection;

/**
 * Los saldos de ausencias de cada persona (Fase 11, R3; PLAN-FASE-11 §7.6; W-060 a W-064; D-362 y
 * D-363), como en Woffu: lo asignado del año (y lo arrastrado del anterior con su caducidad), los
 * ajustes, lo disfrutado, lo pendiente de aprobar y lo disponible. Se calcula siempre a partir del
 * libro de movimientos (`leave_movements`, solo alta) y de las ausencias aprobadas y pendientes de
 * los tipos con saldo, con LeaveAllocator: gasta primero lo que caduca antes.
 *
 * Solo cuentan los días desde el inicio de los saldos en la app (ajuste `people_leave_starts_on`):
 * lo de antes ya está en el saldo inicial que se trae de Woffu (R5).
 *
 * @phpstan-import-type LotState from LeaveAllocator
 * @phpstan-import-type Uncovered from LeaveAllocator
 *
 * @phpstan-type Carried array{year: int, remaining: int, expires_on: string|null, expired: bool}
 * @phpstan-type Summary array{type: LeaveType, year: int, entitled: int, adjusted: int, total: int, used: int, pending: int, available: int, carried: list<Carried>, expired: int, expiring: list<array{amount: int, expires_on: string}>, lots: list<LotState>, uncovered: list<Uncovered>}
 */
final class LeaveBalances
{
    /** Días antes de la caducidad en los que se avisa (y se marca en pantalla). */
    public const int EXPIRING_DAYS = 30;

    public function __construct(private readonly AbsenceCost $cost) {}

    /** Desde qué día cuentan las ausencias en los saldos de la app (null: desde siempre). */
    public static function startsOn(): ?string
    {
        $value = Setting::get('people_leave_starts_on');

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Los tipos con saldo anual (vacaciones, fuerza mayor…), activos o no si ya tienen movimientos.
     *
     * @return Collection<int, LeaveType>
     */
    public static function allowanceTypes(bool $onlyActive = true): Collection
    {
        return LeaveType::query()
            ->whereNotNull('annual_allowance')
            ->where('annual_allowance', '>', 0)
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los saldos del año de una persona, uno por tipo con saldo.
     *
     * @return list<Summary>
     */
    public function forUser(User $user, int $year, ?string $today = null): array
    {
        return $this->forUsers([$user], $year, $today)[$user->id] ?? [];
    }

    /**
     * Los saldos del año de varias personas, con una consulta de movimientos, una de ausencias y las
     * del coste en total.
     *
     * @param  list<User>  $users
     * @param  Collection<int, LeaveType>|null  $types
     * @return array<int, list<Summary>>
     */
    public function forUsers(array $users, int $year, ?string $today = null, ?Collection $types = null): array
    {
        $today ??= LocalTime::todayString();
        $types ??= self::allowanceTypes();
        $ids = array_map(fn (User $user): int => $user->id, $users);

        if ($ids === [] || $types->isEmpty()) {
            return array_fill_keys($ids, []);
        }

        [$movements, $debits] = $this->load($ids, self::ids($types));
        $result = [];

        foreach ($ids as $userId) {
            $result[$userId] = [];

            foreach ($types as $type) {
                $result[$userId][] = $this->summarize(
                    $type,
                    $year,
                    $today,
                    $movements[$userId][$type->id] ?? [],
                    $debits[$userId][$type->id] ?? [],
                );
            }
        }

        return $result;
    }

    /**
     * Cuánto faltaría si se pidiera esto (0: cabe). Se suma a lo aprobado y a lo pendiente de la
     * persona (sin contar $ignoreId, la que se modifica).
     *
     * @param  array<string, int>  $days  Coste por día de lo que se pide (AbsenceCost).
     * @return array{shortfall: int, available: int}
     */
    public function shortfall(User $user, LeaveType $type, array $days, ?int $ignoreId = null): array
    {
        [$movements, $debits] = $this->load([$user->id], [$type->id], $ignoreId);
        $own = $movements[$user->id][$type->id] ?? [];
        $lots = self::lots($own);
        $existing = [...($debits[$user->id][$type->id] ?? []), ...self::negativeDebits($own)];
        $start = self::startsOn();
        $new = [];

        foreach ($days as $date => $amount) {
            if ($amount > 0 && ($start === null || $date >= $start)) {
                $new[] = ['date' => $date, 'amount' => $amount, 'pending' => true, 'ref' => 'new'];
            }
        }

        $before = LeaveAllocator::allocate($lots, $existing);
        $after = LeaveAllocator::allocate($lots, [...$existing, ...$new]);
        $missing = array_sum(array_map(fn (array $row): int => $row['ref'] === 'new' ? $row['amount'] : 0, $after['uncovered']));

        // Lo disponible en las fechas pedidas: lo que queda de los abonos que valen alguno de esos días.
        $dates = array_keys(array_filter($days, fn (int $amount): bool => $amount > 0));
        $available = 0;
        foreach ($before['lots'] as $lot) {
            foreach ($dates as $date) {
                if ($lot['valid_from'] <= $date && ($lot['expires_on'] === null || $lot['expires_on'] >= $date)) {
                    $available += max($lot['remaining'], 0);

                    break;
                }
            }
        }

        return ['shortfall' => $missing, 'available' => $available];
    }

    /**
     * Lo que caduca pronto (en EXPIRING_DAYS días) sin gastar, por persona y tipo: para el aviso.
     *
     * @param  list<User>  $users
     * @return list<array{user_id: int, type: LeaveType, lot_id: int, amount: int, expires_on: string}>
     */
    public function expiringSoon(array $users, ?string $today = null): array
    {
        $today ??= LocalTime::todayString();
        $limit = date('Y-m-d', (int) strtotime($today.' +'.self::EXPIRING_DAYS.' days'));
        $types = self::allowanceTypes();
        $ids = array_map(fn (User $user): int => $user->id, $users);

        if ($ids === [] || $types->isEmpty()) {
            return [];
        }

        [$movements, $debits] = $this->load($ids, self::ids($types));
        $found = [];

        foreach ($ids as $userId) {
            foreach ($types as $type) {
                $own = $movements[$userId][$type->id] ?? [];
                $state = LeaveAllocator::allocate(self::lots($own), [...($debits[$userId][$type->id] ?? []), ...self::negativeDebits($own)]);

                foreach ($state['lots'] as $lot) {
                    if ($lot['remaining'] > 0 && $lot['expires_on'] !== null && $lot['expires_on'] >= $today && $lot['expires_on'] <= $limit) {
                        $found[] = ['user_id' => $userId, 'type' => $type, 'lot_id' => $lot['id'], 'amount' => $lot['remaining'], 'expires_on' => $lot['expires_on']];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<LeaveMovement>  $movements
     * @param  list<array{date: string, amount: int, pending: bool, year?: int|null, ref?: string}>  $debits
     * @return Summary
     */
    private function summarize(LeaveType $type, int $year, string $today, array $movements, array $debits): array
    {
        $state = LeaveAllocator::allocate(self::lots($movements), [...$debits, ...self::negativeDebits($movements)]);
        $entitled = 0;
        $adjusted = 0;

        foreach ($movements as $movement) {
            if ($movement->year !== $year) {
                continue;
            }

            if ($movement->kind->value === 'accrual') {
                $entitled += $movement->amount;
            } else {
                $adjusted += $movement->amount;
            }
        }

        $used = 0;
        $pending = 0;
        foreach ($debits as $debit) {
            if ((int) substr($debit['date'], 0, 4) !== $year) {
                continue;
            }

            $debit['pending'] ? $pending += $debit['amount'] : $used += $debit['amount'];
        }

        $carried = [];
        $available = 0;
        $expired = 0;
        $expiring = [];
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $reference = max($today, $yearStart);

        foreach ($state['lots'] as $lot) {
            $isExpired = $lot['expires_on'] !== null && $lot['expires_on'] < $reference;

            if ($lot['year'] < $year) {
                // Lo arrastrado de años anteriores que aún vale en este.
                if ($lot['expires_on'] !== null && $lot['expires_on'] >= $yearStart && $lot['remaining'] > 0) {
                    $carried[] = ['year' => $lot['year'], 'remaining' => $lot['remaining'], 'expires_on' => $lot['expires_on'], 'expired' => $isExpired];
                    $available += $isExpired ? 0 : $lot['remaining'];
                }

                continue;
            }

            if ($lot['year'] > $year || $lot['valid_from'] > $yearEnd) {
                continue;
            }

            if ($isExpired) {
                $expired += max($lot['remaining'], 0);
            } else {
                $available += $lot['remaining'];

                if ($lot['remaining'] > 0 && $lot['expires_on'] !== null && $lot['expires_on'] <= date('Y-m-d', (int) strtotime($today.' +'.self::EXPIRING_DAYS.' days'))) {
                    $expiring[] = ['amount' => $lot['remaining'], 'expires_on' => $lot['expires_on']];
                }
            }
        }

        // Lo que no cabe en ningún abono (saldo negativo) de los días de este año.
        $uncovered = array_values(array_filter($state['uncovered'], fn (array $row): bool => (int) substr($row['date'], 0, 4) === $year));
        $available -= array_sum(array_column($uncovered, 'amount'));

        return [
            'type' => $type,
            'year' => $year,
            'entitled' => $entitled,
            'adjusted' => $adjusted,
            'total' => $entitled + $adjusted,
            'used' => $used,
            'pending' => $pending,
            'available' => $available,
            'carried' => $carried,
            'expired' => $expired,
            'expiring' => $expiring,
            'lots' => array_values(array_filter($state['lots'], fn (array $lot): bool => $lot['year'] <= $year)),
            'uncovered' => $uncovered,
        ];
    }

    /**
     * Los movimientos y los cargos (día a día) de unas personas en unos tipos.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $typeIds
     * @return array{0: array<int, array<int, list<LeaveMovement>>>, 1: array<int, array<int, list<array{date: string, amount: int, pending: bool, ref: string}>>>}
     */
    private function load(array $userIds, array $typeIds, ?int $ignoreId = null): array
    {
        $movements = [];
        foreach (LeaveMovement::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('leave_type_id', $typeIds)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get() as $movement) {
            $movements[$movement->user_id][$movement->leave_type_id][] = $movement;
        }

        $start = self::startsOn();
        $absences = Absence::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('leave_type_id', $typeIds)
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->when($start !== null, fn ($query) => $query->where('end_date', '>=', $start))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $costs = $this->cost->forAbsences($absences);
        $debits = [];

        foreach ($absences as $absence) {
            foreach ($costs[$absence->id] ?? [] as $date => $amount) {
                if ($amount <= 0 || ($start !== null && $date < $start)) {
                    continue;
                }

                $debits[$absence->user_id][(int) $absence->leave_type_id][] = [
                    'date' => $date,
                    'amount' => $amount,
                    'pending' => $absence->status === AbsenceStatus::Requested,
                    'ref' => 'absence:'.$absence->id,
                ];
            }
        }

        return [$movements, $debits];
    }

    /**
     * @param  Collection<int, LeaveType>  $types
     * @return list<int>
     */
    private static function ids(Collection $types): array
    {
        return array_values(array_map(fn (LeaveType $type): int => $type->id, $types->all()));
    }

    /**
     * Los abonos: los movimientos positivos.
     *
     * @param  list<LeaveMovement>  $movements
     * @return list<array{id: int, year: int, amount: int, valid_from: string, expires_on: string|null, kind: string}>
     */
    public static function lots(array $movements): array
    {
        $lots = [];

        foreach ($movements as $movement) {
            if ($movement->amount > 0) {
                $lots[] = [
                    'id' => $movement->id,
                    'year' => $movement->year,
                    'amount' => $movement->amount,
                    'valid_from' => $movement->valid_from->toDateString(),
                    'expires_on' => $movement->expires_on?->toDateString(),
                    'kind' => $movement->kind->value,
                ];
            }
        }

        return $lots;
    }

    /**
     * Los movimientos negativos como cargos en su fecha, que gastan primero de su año.
     *
     * @param  list<LeaveMovement>  $movements
     * @return list<array{date: string, amount: int, pending: bool, year: int, ref: string}>
     */
    private static function negativeDebits(array $movements): array
    {
        $debits = [];

        foreach ($movements as $movement) {
            if ($movement->amount < 0) {
                $debits[] = ['date' => $movement->valid_from->toDateString(), 'amount' => -$movement->amount, 'pending' => false, 'year' => $movement->year, 'ref' => 'movement:'.$movement->id];
            }
        }

        return $debits;
    }
}
