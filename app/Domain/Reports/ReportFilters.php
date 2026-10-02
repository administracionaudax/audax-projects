<?php

namespace App\Domain\Reports;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Filtros globales de los informes (SPEC §10), leídos de la URL para poder compartirlos y
 * guardarlos como favoritos. Parámetros (en español):
 *   periodo=semana|mes|trimestre|anio|rango (por defecto mes) · fecha=AAAA-MM-DD (ancla; por
 *   defecto hoy en Madrid) · desde, hasta (solo con rango; máx. 3 años) · comparar=1
 *   persona[] · departamento[] · cliente[] · proyecto[] · bolsa[] · tipo[] · facturable=si|no
 * Valores no válidos se ignoran (nunca error): un enlace viejo o manipulado muestra el mes actual.
 */
final readonly class ReportFilters
{
    public const int MAX_IDS = 50;

    public const int MAX_RANGE_DAYS = 3 * 366;

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $departmentIds
     * @param  list<int>  $clientIds
     * @param  list<int>  $projectIds
     * @param  list<int>  $bankIds
     * @param  list<int>  $taskTypeIds
     */
    public function __construct(
        public ReportPeriod $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $compare = false,
        public array $userIds = [],
        public array $departmentIds = [],
        public array $clientIds = [],
        public array $projectIds = [],
        public array $bankIds = [],
        public array $taskTypeIds = [],
        public ?bool $billable = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, ?CarbonImmutable $today = null): self
    {
        $today ??= LocalTime::today();
        $period = ReportPeriod::tryFrom(is_string($query['periodo'] ?? null) ? $query['periodo'] : '') ?? ReportPeriod::Month;
        $anchor = self::date($query['fecha'] ?? null) ?? $today;

        if ($period === ReportPeriod::Range) {
            $from = self::date($query['desde'] ?? null);
            $to = self::date($query['hasta'] ?? null);

            if ($from === null || $to === null || $to < $from || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $period = ReportPeriod::Month;
                [$from, $to] = $period->bounds($today);
            }
        } else {
            [$from, $to] = $period->bounds($anchor);
        }

        $billable = match ($query['facturable'] ?? null) {
            'si' => true,
            'no' => false,
            default => null,
        };

        return new self(
            period: $period,
            from: $from,
            to: $to,
            compare: in_array($query['comparar'] ?? null, ['1', 1, true, 'true', 'si'], true),
            userIds: self::ids($query['persona'] ?? null),
            departmentIds: self::ids($query['departamento'] ?? null),
            clientIds: self::ids($query['cliente'] ?? null),
            projectIds: self::ids($query['proyecto'] ?? null),
            bankIds: self::ids($query['bolsa'] ?? null),
            taskTypeIds: self::ids($query['tipo'] ?? null),
            billable: $billable,
        );
    }

    /**
     * El periodo anterior (o siguiente) del mismo tipo, con los mismos filtros.
     */
    public function shifted(int $steps): self
    {
        if ($this->period === ReportPeriod::Range) {
            $days = (int) $this->from->diffInDays($this->to) + 1;
            $from = $this->from->addDays($days * $steps);

            return $this->withDates($from, $from->addDays($days - 1));
        }

        [$from, $to] = $this->period->bounds($this->period->shift($this->from, $steps));

        return $this->withDates($from, $to);
    }

    /**
     * Periodo de comparación (SPEC §10: «comparación con el periodo anterior»).
     */
    public function comparison(): self
    {
        return $this->shifted(-1);
    }

    /**
     * Los mismos filtros sin comparar con el periodo anterior (para las páginas que no comparan,
     * como Facturación: sin comparar=1 en sus enlaces).
     */
    public function withoutComparison(): self
    {
        return new self($this->period, $this->from, $this->to, false, $this->userIds, $this->departmentIds,
            $this->clientIds, $this->projectIds, $this->bankIds, $this->taskTypeIds, $this->billable);
    }

    public function withDates(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($this->period, $from, $to, $this->compare, $this->userIds, $this->departmentIds,
            $this->clientIds, $this->projectIds, $this->bankIds, $this->taskTypeIds, $this->billable);
    }

    /**
     * Mismo periodo con filtros adicionales fijos (p. ej. el dashboard de un cliente).
     *
     * @param  array{userIds?: list<int>, departmentIds?: list<int>, clientIds?: list<int>, projectIds?: list<int>, bankIds?: list<int>, taskTypeIds?: list<int>}  $fixed
     */
    public function with(array $fixed): self
    {
        return new self($this->period, $this->from, $this->to, $this->compare,
            $fixed['userIds'] ?? $this->userIds,
            $fixed['departmentIds'] ?? $this->departmentIds,
            $fixed['clientIds'] ?? $this->clientIds,
            $fixed['projectIds'] ?? $this->projectIds,
            $fixed['bankIds'] ?? $this->bankIds,
            $fixed['taskTypeIds'] ?? $this->taskTypeIds,
            $this->billable);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Parámetros de URL equivalentes (para enlaces, exportaciones y el frontend).
     *
     * @return array<string, string|list<int>>
     */
    public function toQuery(): array
    {
        $query = ['periodo' => $this->period->value];

        if ($this->period === ReportPeriod::Range) {
            $query['desde'] = $this->from->toDateString();
            $query['hasta'] = $this->to->toDateString();
        } else {
            $query['fecha'] = $this->from->toDateString();
        }

        if ($this->compare) {
            $query['comparar'] = '1';
        }

        foreach (['persona' => $this->userIds, 'departamento' => $this->departmentIds, 'cliente' => $this->clientIds,
            'proyecto' => $this->projectIds, 'bolsa' => $this->bankIds, 'tipo' => $this->taskTypeIds] as $key => $ids) {
            if ($ids !== []) {
                $query[$key] = $ids;
            }
        }

        if ($this->billable !== null) {
            $query['facturable'] = $this->billable ? 'si' : 'no';
        }

        return $query;
    }

    /**
     * Clave estable para la caché (D-046).
     */
    public function cacheKey(): string
    {
        return md5((string) json_encode([$this->from->toDateString(), $this->to->toDateString(), $this->userIds,
            $this->departmentIds, $this->clientIds, $this->projectIds, $this->bankIds, $this->taskTypeIds, $this->billable]));
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : (is_string($value) && $value !== '' ? explode(',', $value) : []);
        $ids = [];

        foreach ($values as $item) {
            if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return array_slice($ids, 0, self::MAX_IDS);
    }
}
