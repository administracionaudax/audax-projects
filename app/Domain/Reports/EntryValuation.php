<?php

namespace App\Domain\Reports;

use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Valoración de cada entrada suelta con el criterio de D-043 (exportaciones con importes por
 * entrada: facturación y horas), con las mismas fórmulas que RevenueCalculator (Valuation):
 *
 * - no facturable o proyecto interno: 0,
 * - precio cerrado: importe × minutos / base de avance (RevenueCalculator::fixedPriceBases),
 * - bolsa con precio: precio × minutos dentro / divisor (D-082) + exceso × (instantánea de tarifa
 *   si la tiene, aprobada o bloqueada; si no, la tarifa vigente) (D-043, BIZ-01),
 * - resto: minutos × (instantánea de tarifa si la tiene; si no, la tarifa vigente).
 *
 * Dos usos:
 * - value() y valueOf(): el importe de una entrada por sí sola,
 * - next(), en streaming (PERF-02): la parte de cada entrada del total de su unidad, en el orden
 *   de la exportación. Es lo que suma al acumulado de su unidad, así que la suma de las entradas
 *   es EXACTAMENTE el total canónico de RevenueCalculator para esas entradas (sin el sesgo del
 *   truncado de cada una) y, con Cents::running, el fichero cuadra con la página sin forzar nada.
 *
 * Se prepara una vez para una consulta ya acotada (ReportScope::entries()): carga proyectos,
 * clientes, bolsas y personas con un número fijo de consultas; después no consulta.
 *
 * @phpstan-import-type Sums from Valuation
 * @phpstan-import-type Unit from Valuation
 */
final class EntryValuation
{
    public const string NOT_BILLABLE = 'not_billable';

    public const string INTERNAL = 'internal';

    public const string FIXED_PRICE = 'fixed_price';

    public const string BANK_PRICE = 'bank_price';

    public const string SNAPSHOT = 'snapshot';

    public const string RATE = 'rate';

    public const string NO_RATE = 'no_rate';

    /** @var array<string, array{sums: Sums, income: numeric-string, cost: numeric-string}> acumulado de next() por unidad */
    private array $running = [];

    /** @var array<int, array{minutes: int, income: numeric-string}> acumulado de next() por proyecto de precio cerrado */
    private array $fixed = [];

    /** @var numeric-string */
    private string $incomeTotal = '0';

    /** @var numeric-string */
    private string $costTotal = '0';

    private function __construct(private readonly Valuation $valuation) {}

    /**
     * @param  Builder<TimeEntry>  $entries  Consulta ya acotada (ReportScope::entries()).
     */
    public static function for(Builder $entries): self
    {
        $keys = (clone $entries)->toBase()->distinct()
            ->select(['time_entries.project_id', 'time_entries.hour_bank_id', 'time_entries.user_id'])
            ->get();
        $ids = fn (string $column): array => array_values($keys->pluck($column)->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)->unique()->all());

        return new self(Valuation::load(app(RevenueCalculator::class), $ids('project_id'), $ids('hour_bank_id'), $ids('user_id')));
    }

    /**
     * Tarifa aplicada (€/h, o null si no hay tarifa por horas), importe exacto (6 decimales: se
     * redondea al presentar) y criterio de valoración (constantes de esta clase).
     *
     * @return array{rate: string|null, income: numeric-string, basis: string}
     */
    public function value(TimeEntry $entry): array
    {
        return $this->valueOf(
            $entry->project_id,
            $entry->hour_bank_id,
            $entry->user_id,
            $entry->is_billable,
            $entry->minutes,
            $entry->overage_minutes,
            $entry->getRawOriginal('hourly_rate_snapshot'),
        );
    }

    /**
     * La misma valoración a partir de las columnas de la entrada (filas sin hidratar: las
     * exportaciones leen miles de entradas sin crear un modelo por cada una).
     *
     * @return array{rate: string|null, income: numeric-string, basis: string}
     */
    public function valueOf(int $projectId, ?int $bankId, int $userId, bool $billable, int $minutes, int $overage, mixed $rateSnapshot): array
    {
        $unit = $this->valuation->unit($projectId, $bankId, $userId, $billable);
        $sums = Valuation::ofEntry($minutes, $overage, $rateSnapshot, null);
        $described = self::describe($unit, $rateSnapshot);

        return [
            'rate' => $described['rate'],
            'income' => $unit['basis'] === self::FIXED_PRICE
                ? $this->valuation->fixedIncome($projectId, $minutes)
                : $this->valuation->income($unit, $sums),
            'basis' => $described['basis'],
        ];
    }

    /**
     * La siguiente entrada de una exportación (en su orden): su tarifa y criterio y su parte exacta
     * del ingreso y del coste, que es lo que suma al acumulado de su unidad (proyecto · bolsa ·
     * persona · facturable; el precio cerrado, por proyecto). La suma de todas es totals().
     *
     * @return array{rate: string|null, income: numeric-string, cost: numeric-string, basis: string}
     */
    public function next(int $projectId, ?int $bankId, int $userId, bool $billable, int $minutes, int $overage, mixed $rateSnapshot, mixed $costSnapshot): array
    {
        $unit = $this->valuation->unit($projectId, $bankId, $userId, $billable);
        $key = $projectId.':'.($bankId ?? '-').':'.$userId.':'.($billable ? 1 : 0);
        $before = $this->running[$key] ?? ['sums' => Valuation::zero(), 'income' => '0', 'cost' => '0'];
        $sums = Valuation::add($before['sums'], Valuation::ofEntry($minutes, $overage, $rateSnapshot, $costSnapshot));

        $after = [
            'sums' => $sums,
            'income' => $unit['basis'] === self::FIXED_PRICE ? '0' : $this->valuation->income($unit, $sums),
            'cost' => $this->valuation->cost($unit, $sums),
        ];
        $this->running[$key] = $after;

        $income = Money::sub($after['income'], $before['income']);
        if ($unit['basis'] === self::FIXED_PRICE) {
            $fixed = $this->fixed[$projectId] ?? ['minutes' => 0, 'income' => '0'];
            $total = $this->valuation->fixedIncome($projectId, $fixed['minutes'] + $minutes);
            $this->fixed[$projectId] = ['minutes' => $fixed['minutes'] + $minutes, 'income' => $total];
            $income = Money::sub($total, $fixed['income']);
        }

        $cost = Money::sub($after['cost'], $before['cost']);
        $this->incomeTotal = Money::add($this->incomeTotal, $income);
        $this->costTotal = Money::add($this->costTotal, $cost);

        $described = self::describe($unit, $rateSnapshot);

        return ['rate' => $described['rate'], 'income' => $income, 'cost' => $cost, 'basis' => $described['basis']];
    }

    /**
     * Ingreso y coste exactos de las entradas pasadas por next(): el total canónico de
     * RevenueCalculator para esas mismas entradas.
     *
     * @return array{income: numeric-string, cost: numeric-string}
     */
    public function totals(): array
    {
        return ['income' => $this->incomeTotal, 'cost' => $this->costTotal];
    }

    /**
     * Tarifa que se enseña y criterio de una entrada: con instantánea (aprobada o bloqueada), su
     * tarifa congelada, también la del exceso de una bolsa con precio (BIZ-01); si no, la vigente.
     *
     * @param  Unit  $unit
     * @return array{rate: string|null, basis: string}
     */
    private static function describe(array $unit, mixed $rateSnapshot): array
    {
        $snapshot = Valuation::snapshot($rateSnapshot);

        return match ($unit['basis']) {
            self::BANK_PRICE => ['rate' => $snapshot ?? $unit['rate'], 'basis' => self::BANK_PRICE],
            self::RATE, self::NO_RATE => $snapshot !== null
                ? ['rate' => $snapshot, 'basis' => self::SNAPSHOT]
                : ['rate' => $unit['rate'], 'basis' => $unit['basis']],
            default => ['rate' => null, 'basis' => $unit['basis']],
        };
    }
}
