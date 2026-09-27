<?php

namespace App\Domain\Reports;

use App\Enums\BillingType;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Valoración de cada entrada suelta con el criterio de D-043 (exportación para facturar, R2): la
 * misma que aplica RevenueCalculator a los agregados, entrada a entrada, para que la suma de los
 * importes de las entradas sea el ingreso estimado del informe.
 *
 * - no facturable o proyecto interno: 0,
 * - precio cerrado: importe × minutos / base de avance (RevenueCalculator::fixedPriceBases),
 * - bolsa con precio: precio × minutos dentro / total de la bolsa + exceso × tarifa vigente,
 * - resto: minutos × (instantánea de tarifa si la tiene; si no, la tarifa vigente).
 *
 * Se prepara una vez para una consulta ya acotada (ReportScope::entries()): carga proyectos,
 * clientes, bolsas y personas con un número fijo de consultas; después value() no consulta.
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

    /** @var array<string, array{basis: string, rate: string|null, price: string|null, total: int}> */
    private array $contexts = [];

    /**
     * @param  Builder<TimeEntry>  $entries  Consulta ya acotada (ReportScope::entries()).
     */
    public static function for(Builder $entries): self
    {
        $revenue = app(RevenueCalculator::class);
        $keys = (clone $entries)->toBase()->distinct()
            ->select(['time_entries.project_id', 'time_entries.hour_bank_id', 'time_entries.user_id'])
            ->get();

        $projects = Project::query()->withTrashed()
            ->whereIn('id', $keys->pluck('project_id')->map(fn ($id): int => (int) $id)->unique()->values())
            ->get(['id', 'client_id', 'billing_type', 'fixed_price_amount', 'budget_minutes', 'hourly_rate'])
            ->keyBy('id');
        $clients = Client::query()->withTrashed()
            ->whereIn('id', $projects->pluck('client_id')->filter()->unique()->values())
            ->get(['id', 'default_hourly_rate'])->keyBy('id');
        $banks = HourBank::query()->withTrashed()
            ->whereIn('id', $keys->pluck('hour_bank_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values())
            ->get(['id', 'total_minutes', 'hourly_rate', 'price_amount'])->keyBy('id');
        $users = User::query()
            ->whereIn('id', $keys->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values())
            ->get(['id', 'default_hourly_rate', 'hourly_cost'])->keyBy('id');

        $fixedBases = $revenue->fixedPriceBases(
            $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::FixedPrice),
        );

        return new self($revenue, $projects, $clients, $banks, $users, $fixedBases);
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
     * La misma valoración a partir de las columnas de la entrada (filas sin hidratar: la exportación
     * para facturar lee miles de entradas sin crear un modelo por cada una).
     *
     * @return array{rate: string|null, income: numeric-string, basis: string}
     */
    public function valueOf(int $projectId, ?int $bankId, int $userId, bool $billable, int $minutes, int $overage, mixed $rateSnapshot): array
    {
        if (! $billable) {
            return ['rate' => null, 'income' => '0', 'basis' => self::NOT_BILLABLE];
        }

        $context = $this->context($projectId, $bankId, $userId);

        return match ($context['basis']) {
            self::INTERNAL => ['rate' => null, 'income' => '0', 'basis' => self::INTERNAL],
            self::FIXED_PRICE => [
                'rate' => null,
                'income' => $context['price'] === null ? '0' : Money::div(Money::mul($context['price'], (string) $minutes), (string) $context['total']),
                'basis' => self::FIXED_PRICE,
            ],
            self::BANK_PRICE => [
                'rate' => $context['rate'],
                'income' => Money::add(
                    Money::div(Money::mul((string) $context['price'], (string) ($minutes - $overage)), (string) $context['total']),
                    Money::forMinutes($overage, $context['rate']),
                ),
                'basis' => self::BANK_PRICE,
            ],
            default => $this->hourly($minutes, $rateSnapshot, $context['rate']),
        };
    }

    /**
     * Por horas: la instantánea de tarifa si la entrada la tiene (aprobada o bloqueada); si no, la
     * tarifa vigente.
     *
     * @return array{rate: string|null, income: numeric-string, basis: string}
     */
    private function hourly(int $minutes, mixed $snapshot, ?string $rate): array
    {
        if (is_numeric($snapshot)) {
            $snapshot = Money::round(Money::of(is_string($snapshot) ? $snapshot : (float) $snapshot));

            return [
                'rate' => $snapshot,
                'income' => Money::forMinutes($minutes, $snapshot),
                'basis' => self::SNAPSHOT,
            ];
        }

        return [
            'rate' => $rate,
            'income' => Money::forMinutes($minutes, $rate),
            'basis' => $rate === null ? self::NO_RATE : self::RATE,
        ];
    }

    /**
     * Lo que no depende de la entrada sino de su proyecto, bolsa y persona (criterio, tarifa vigente,
     * precio y total o base), calculado una sola vez por combinación: valorar miles de entradas no
     * repite la lectura de los atributos (con sus conversiones) de los mismos modelos.
     *
     * @return array{basis: string, rate: string|null, price: string|null, total: int}
     */
    private function context(int $projectId, ?int $bankId, int $userId): array
    {
        $key = $projectId.':'.($bankId ?? '-').':'.$userId;

        return $this->contexts[$key] ??= $this->resolveContext($projectId, $bankId, $userId);
    }

    /**
     * @return array{basis: string, rate: string|null, price: string|null, total: int}
     */
    private function resolveContext(int $projectId, ?int $bankId, int $userId): array
    {
        /** @var Project|null $project */
        $project = $this->projects->get($projectId);

        if ($project === null || $project->billing_type === BillingType::Internal) {
            return ['basis' => self::INTERNAL, 'rate' => null, 'price' => null, 'total' => 0];
        }

        if ($project->billing_type === BillingType::FixedPrice) {
            $base = $this->fixedBases[$project->id] ?? 0;

            return [
                'basis' => self::FIXED_PRICE,
                'rate' => null,
                'price' => $project->fixed_price_amount === null || $base <= 0 ? null : (string) $project->fixed_price_amount,
                'total' => $base,
            ];
        }

        /** @var HourBank|null $bank */
        $bank = $bankId !== null ? $this->banks->get($bankId) : null;
        /** @var User|null $user */
        $user = $this->users->get($userId);
        /** @var Client|null $client */
        $client = $project->client_id !== null ? $this->clients->get($project->client_id) : null;
        $rate = $this->revenue->rate($bank, $project, $client, $user);

        if ($bank !== null && $bank->price_amount !== null && $bank->total_minutes > 0) {
            return ['basis' => self::BANK_PRICE, 'rate' => $rate, 'price' => (string) $bank->price_amount, 'total' => $bank->total_minutes];
        }

        return ['basis' => self::RATE, 'rate' => $rate, 'price' => null, 'total' => 0];
    }
}
