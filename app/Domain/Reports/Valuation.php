<?php

namespace App\Domain\Reports;

use App\Enums\BillingType;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Las fórmulas del ingreso estimado (D-043) y del coste en un solo sitio. Carga una vez los
 * proyectos, clientes, bolsas y personas de un conjunto de entradas y valora las SUMAS de las
 * entradas de cada «unidad» (proyecto · bolsa · persona · facturable), sin más consultas. La usan
 * RevenueCalculator (sumas agregadas en SQL) y EntryValuation (entrada a entrada, en streaming):
 * las dos dan exactamente el mismo total para las mismas entradas.
 *
 * Ingreso de una unidad facturable (los proyectos internos y las horas no facturables dan 0):
 * - bolsa con precio: precio × minutos dentro / divisor, con divisor = máx(total de la bolsa, lo
 *   que va dentro de la bolsa entera según HourBankLedger): el precio nunca se reparte por encima
 *   de sí mismo (D-082, BIZ-06), más el exceso a la instantánea de tarifa de cada entrada si está
 *   aprobada o bloqueada y, si no, a la tarifa vigente (D-043, BIZ-01),
 * - precio cerrado: por proyecto, importe × minutos facturables / base de avance
 *   (RevenueCalculator::fixedPriceBases),
 * - por horas: la instantánea de tarifa de las entradas que la tienen y la tarifa vigente
 *   (bolsa > proyecto > cliente > persona) del resto.
 * Coste: la instantánea de coste si existe; si no, el coste por hora actual de la persona.
 *
 * @phpstan-type Unit array{basis: string, rate: string|null, price: string|null, divisor: int, cost: string|null, project: int}
 * @phpstan-type Sums array{minutes: int, overage: int, rate_snap_minutes: int, rate_snap_amount: numeric-string,
 *     overage_snap_minutes: int, overage_snap_amount: numeric-string, cost_snap_minutes: int, cost_snap_amount: numeric-string}
 */
final class Valuation
{
    /**
     * Las mismas sumas en SQL, sobre time_entries (RevenueCalculator las agrupa por unidad).
     */
    public const string SUMS_SQL = 'SUM(time_entries.minutes) as minutes,
        SUM(time_entries.overage_minutes) as overage,
        SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.minutes ELSE 0 END) as rate_snap_minutes,
        SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.minutes * time_entries.hourly_rate_snapshot ELSE 0 END) as rate_snap_amount,
        SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.overage_minutes ELSE 0 END) as overage_snap_minutes,
        SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.overage_minutes * time_entries.hourly_rate_snapshot ELSE 0 END) as overage_snap_amount,
        SUM(CASE WHEN time_entries.hourly_cost_snapshot IS NOT NULL THEN time_entries.minutes ELSE 0 END) as cost_snap_minutes,
        SUM(CASE WHEN time_entries.hourly_cost_snapshot IS NOT NULL THEN time_entries.minutes * time_entries.hourly_cost_snapshot ELSE 0 END) as cost_snap_amount';

    /** @var array<string, Unit> */
    private array $units = [];

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Client>  $clients
     * @param  Collection<int, HourBank>  $banks
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $fixedBases
     */
    private function __construct(
        private readonly RevenueCalculator $revenue,
        private readonly Collection $projects,
        private readonly Collection $clients,
        private readonly Collection $banks,
        private readonly Collection $users,
        private readonly array $fixedBases,
    ) {}

    /**
     * Carga lo necesario para valorar las entradas de estos proyectos, bolsas y personas (también
     * borrados: sus horas siguen en los informes). Hasta 7 consultas.
     *
     * @param  list<int>  $projectIds
     * @param  list<int>  $bankIds
     * @param  list<int>  $userIds
     */
    public static function load(RevenueCalculator $revenue, array $projectIds, array $bankIds, array $userIds): self
    {
        $projects = Project::query()->withTrashed()->whereIn('id', array_values(array_unique($projectIds)))
            ->get(['id', 'client_id', 'billing_type', 'fixed_price_amount', 'budget_minutes', 'hourly_rate'])->keyBy('id');
        $clients = Client::query()->withTrashed()->whereIn('id', $projects->pluck('client_id')->filter()->unique()->values())
            ->get(['id', 'default_hourly_rate'])->keyBy('id');
        $banks = $bankIds === [] ? new Collection : HourBank::query()->withTrashed()->whereIn('id', array_values(array_unique($bankIds)))
            ->get(['id', 'total_minutes', 'hourly_rate', 'price_amount', 'consumed_minutes', 'overage_minutes'])->keyBy('id');
        $users = User::query()->whereIn('id', array_values(array_unique($userIds)))
            ->get(['id', 'default_hourly_rate', 'hourly_cost'])->keyBy('id');

        $fixedBases = $revenue->fixedPriceBases(
            $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::FixedPrice),
        );

        return new self($revenue, $projects, $clients, $banks, $users, $fixedBases);
    }

    /**
     * Cómo se valora una unidad (una vez por combinación): criterio (constantes de EntryValuation),
     * tarifa vigente, precio (de la bolsa o el cerrado), divisor del precio y coste por hora.
     *
     * @return Unit
     */
    public function unit(int $projectId, ?int $bankId, int $userId, bool $billable): array
    {
        $key = $projectId.':'.($bankId ?? '-').':'.$userId.':'.($billable ? 1 : 0);

        return $this->units[$key] ??= $this->resolve($projectId, $bankId, $userId, $billable);
    }

    /**
     * Ingreso de las sumas de una unidad. El de precio cerrado va por proyecto (fixedIncome): aquí, 0.
     *
     * @param  Unit  $unit
     * @param  Sums  $sums
     * @return numeric-string
     */
    public function income(array $unit, array $sums): string
    {
        return match ($unit['basis']) {
            EntryValuation::BANK_PRICE => Money::add(
                Money::div(Money::mul((string) $unit['price'], (string) ($sums['minutes'] - $sums['overage'])), (string) $unit['divisor']),
                Money::div($sums['overage_snap_amount'], '60'),
                Money::forMinutes($sums['overage'] - $sums['overage_snap_minutes'], $unit['rate']),
            ),
            EntryValuation::RATE, EntryValuation::NO_RATE => Money::add(
                Money::div($sums['rate_snap_amount'], '60'),
                Money::forMinutes($sums['minutes'] - $sums['rate_snap_minutes'], $unit['rate']),
            ),
            default => '0',
        };
    }

    /**
     * Ingreso de un proyecto de precio cerrado por sus minutos facturables (0 sin importe o sin base).
     *
     * @return numeric-string
     */
    public function fixedIncome(int $projectId, int $minutes): string
    {
        /** @var Project|null $project */
        $project = $this->projects->get($projectId);
        $base = $this->fixedBases[$projectId] ?? 0;

        if ($project === null || $project->fixed_price_amount === null || $base <= 0) {
            return '0';
        }

        return Money::div(Money::mul((string) $project->fixed_price_amount, (string) $minutes), (string) $base);
    }

    /**
     * Coste de las sumas de una unidad.
     *
     * @param  Unit  $unit
     * @param  Sums  $sums
     * @return numeric-string
     */
    public function cost(array $unit, array $sums): string
    {
        return Money::add(
            Money::div($sums['cost_snap_amount'], '60'),
            Money::forMinutes($sums['minutes'] - $sums['cost_snap_minutes'], $unit['cost']),
        );
    }

    /**
     * Sumas vacías.
     *
     * @return Sums
     */
    public static function zero(): array
    {
        return ['minutes' => 0, 'overage' => 0, 'rate_snap_minutes' => 0, 'rate_snap_amount' => '0',
            'overage_snap_minutes' => 0, 'overage_snap_amount' => '0', 'cost_snap_minutes' => 0, 'cost_snap_amount' => '0'];
    }

    /**
     * Sumas de una fila agregada en SQL (SUMS_SQL).
     *
     * @param  array<string, mixed>  $row
     * @return Sums
     */
    public static function fromRow(array $row): array
    {
        return [
            'minutes' => (int) $row['minutes'],
            'overage' => (int) $row['overage'],
            'rate_snap_minutes' => (int) $row['rate_snap_minutes'],
            'rate_snap_amount' => Money::of($row['rate_snap_amount']),
            'overage_snap_minutes' => (int) $row['overage_snap_minutes'],
            'overage_snap_amount' => Money::of($row['overage_snap_amount']),
            'cost_snap_minutes' => (int) $row['cost_snap_minutes'],
            'cost_snap_amount' => Money::of($row['cost_snap_amount']),
        ];
    }

    /**
     * Sumas de una sola entrada (las instantáneas, tal como llegan de la base: texto, número o null).
     *
     * @return Sums
     */
    public static function ofEntry(int $minutes, int $overage, mixed $rateSnapshot, mixed $costSnapshot): array
    {
        $rate = self::snapshot($rateSnapshot);
        $cost = self::snapshot($costSnapshot);

        return [
            'minutes' => $minutes,
            'overage' => $overage,
            'rate_snap_minutes' => $rate === null ? 0 : $minutes,
            'rate_snap_amount' => $rate === null ? '0' : Money::mul((string) $minutes, $rate),
            'overage_snap_minutes' => $rate === null ? 0 : $overage,
            'overage_snap_amount' => $rate === null ? '0' : Money::mul((string) $overage, $rate),
            'cost_snap_minutes' => $cost === null ? 0 : $minutes,
            'cost_snap_amount' => $cost === null ? '0' : Money::mul((string) $minutes, $cost),
        ];
    }

    /**
     * @param  Sums  $a
     * @param  Sums  $b
     * @return Sums
     */
    public static function add(array $a, array $b): array
    {
        return [
            'minutes' => $a['minutes'] + $b['minutes'],
            'overage' => $a['overage'] + $b['overage'],
            'rate_snap_minutes' => $a['rate_snap_minutes'] + $b['rate_snap_minutes'],
            'rate_snap_amount' => Money::add($a['rate_snap_amount'], $b['rate_snap_amount']),
            'overage_snap_minutes' => $a['overage_snap_minutes'] + $b['overage_snap_minutes'],
            'overage_snap_amount' => Money::add($a['overage_snap_amount'], $b['overage_snap_amount']),
            'cost_snap_minutes' => $a['cost_snap_minutes'] + $b['cost_snap_minutes'],
            'cost_snap_amount' => Money::add($a['cost_snap_amount'], $b['cost_snap_amount']),
        ];
    }

    /**
     * Una instantánea (tarifa o coste) como importe con 2 decimales, o null si no la hay.
     *
     * @return numeric-string|null
     */
    public static function snapshot(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Money::round(Money::of(is_string($value) ? $value : (float) $value));
    }

    /**
     * ¿Es una unidad que lleva ingreso (facturable, de un proyecto que no es interno)?
     *
     * @param  Unit  $unit
     */
    public static function earns(array $unit): bool
    {
        return ! in_array($unit['basis'], [EntryValuation::NOT_BILLABLE, EntryValuation::INTERNAL], true);
    }

    /**
     * @return Unit
     */
    private function resolve(int $projectId, ?int $bankId, int $userId, bool $billable): array
    {
        /** @var User|null $user */
        $user = $this->users->get($userId);
        $cost = $user?->hourly_cost !== null && $user->hourly_cost !== '' ? (string) $user->hourly_cost : null;
        /** @var Project|null $project */
        $project = $this->projects->get($projectId);

        if (! $billable) {
            return ['basis' => EntryValuation::NOT_BILLABLE, 'rate' => null, 'price' => null, 'divisor' => 0, 'cost' => $cost, 'project' => $projectId];
        }

        if ($project === null || $project->billing_type === BillingType::Internal) {
            return ['basis' => EntryValuation::INTERNAL, 'rate' => null, 'price' => null, 'divisor' => 0, 'cost' => $cost, 'project' => $projectId];
        }

        if ($project->billing_type === BillingType::FixedPrice) {
            return ['basis' => EntryValuation::FIXED_PRICE, 'rate' => null, 'price' => $project->fixed_price_amount, 'divisor' => $this->fixedBases[$projectId] ?? 0, 'cost' => $cost, 'project' => $projectId];
        }

        /** @var HourBank|null $bank */
        $bank = $bankId !== null ? $this->banks->get($bankId) : null;
        /** @var Client|null $client */
        $client = $project->client_id !== null ? $this->clients->get($project->client_id) : null;
        $rate = $this->revenue->rate($bank, $project, $client, $user);

        if ($bank !== null && $bank->price_amount !== null && $bank->total_minutes > 0) {
            // Tope del precio (D-082): si lo que va dentro de la bolsa supera su total (una bloqueada
            // que no cambia al reducir el total, D-054), el precio se reparte entre todo lo de dentro.
            $divisor = max($bank->total_minutes, $bank->consumed_minutes - $bank->overage_minutes);

            return ['basis' => EntryValuation::BANK_PRICE, 'rate' => $rate, 'price' => (string) $bank->price_amount, 'divisor' => $divisor, 'cost' => $cost, 'project' => $projectId];
        }

        return ['basis' => $rate === null ? EntryValuation::NO_RATE : EntryValuation::RATE, 'rate' => $rate, 'price' => null, 'divisor' => 0, 'cost' => $cost, 'project' => $projectId];
    }
}
