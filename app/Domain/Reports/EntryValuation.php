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
        /** @var Project|null $project */
        $project = $this->projects->get($entry->project_id);

        if (! $entry->is_billable) {
            return ['rate' => null, 'income' => '0', 'basis' => self::NOT_BILLABLE];
        }

        if ($project === null || $project->billing_type === BillingType::Internal) {
            return ['rate' => null, 'income' => '0', 'basis' => self::INTERNAL];
        }

        if ($project->billing_type === BillingType::FixedPrice) {
            $base = $this->fixedBases[$project->id] ?? 0;
            $income = $project->fixed_price_amount === null || $base <= 0
                ? '0'
                : Money::div(Money::mul($project->fixed_price_amount, (string) $entry->minutes), (string) $base);

            return ['rate' => null, 'income' => $income, 'basis' => self::FIXED_PRICE];
        }

        /** @var HourBank|null $bank */
        $bank = $entry->hour_bank_id !== null ? $this->banks->get($entry->hour_bank_id) : null;
        /** @var User|null $user */
        $user = $this->users->get($entry->user_id);
        /** @var Client|null $client */
        $client = $project->client_id !== null ? $this->clients->get($project->client_id) : null;
        $rate = $this->revenue->rate($bank, $project, $client, $user);

        if ($bank !== null && $bank->price_amount !== null && $bank->total_minutes > 0) {
            $overage = $entry->overage_minutes;

            return [
                'rate' => $rate,
                'income' => Money::add(
                    Money::div(Money::mul($bank->price_amount, (string) ($entry->minutes - $overage)), (string) $bank->total_minutes),
                    Money::forMinutes($overage, $rate),
                ),
                'basis' => self::BANK_PRICE,
            ];
        }

        if ($entry->hourly_rate_snapshot !== null) {
            return [
                'rate' => (string) $entry->hourly_rate_snapshot,
                'income' => Money::forMinutes($entry->minutes, (string) $entry->hourly_rate_snapshot),
                'basis' => self::SNAPSHOT,
            ];
        }

        return [
            'rate' => $rate,
            'income' => Money::forMinutes($entry->minutes, $rate),
            'basis' => $rate === null ? self::NO_RATE : self::RATE,
        ];
    }
}
