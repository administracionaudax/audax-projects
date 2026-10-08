<?php

namespace App\Domain\Billing;

use App\Domain\Reports\ReportFilters;
use App\Enums\BillingService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Filtros del informe de facturación (D-400): el periodo y los clientes de la barra de informes
 * (ReportFilters: ?periodo=, ?fecha=, ?desde=, ?hasta=, ?cliente[]=, ?comparar=1) más el servicio
 * (?servicio[]=bolsas|fees|…, BillingService). Sin periodo en la URL, el año en curso comparado con
 * el anterior. La comparación es SIEMPRE con el mismo periodo del año anterior (no con el periodo
 * anterior, como en el resto de informes): así un trimestre se compara con el mismo trimestre.
 */
final readonly class InvoicingQuery
{
    /**
     * @param  list<BillingService>  $services
     */
    public function __construct(
        public ReportFilters $filters,
        public array $services = [],
        public ?CarbonImmutable $today = null,
    ) {}

    /**
     * La query de la URL con los valores por defecto: sin periodo, el año en curso y comparado.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function withDefaults(array $query): array
    {
        return isset($query['periodo']) ? $query : ['periodo' => 'anio', 'comparar' => '1', ...$query];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, ?CarbonImmutable $today = null): self
    {
        $query = self::withDefaults($query);

        return new self(ReportFilters::fromQuery($query, $today), BillingService::fromQuery($query['servicio'] ?? []), $today);
    }

    /** ¿Se pinta el año anterior (gráfica, variaciones de cada cifra y columnas de las tablas)? */
    public function compares(): bool
    {
        return $this->filters->compare;
    }

    /** Primer día del mismo periodo del año anterior (29/02 → 28/02). */
    public function previousFrom(): CarbonImmutable
    {
        return $this->filters->from->subYearNoOverflow();
    }

    /**
     * Último día del periodo del año anterior con el que se comparan las cifras: con el periodo en
     * curso, hasta el mismo día de hoy del año anterior (del 1/1 al 8/10 de 2026 se compara con del
     * 1/1 al 8/10 de 2025, no con todo 2025); con uno pasado, su mismo final.
     */
    public function previousTo(): CarbonImmutable
    {
        $today = ($this->today ?? LocalTime::today())->startOfDay();
        $to = $this->filters->to->greaterThan($today) && $this->filters->from->lessThanOrEqualTo($today) ? $today : $this->filters->to;

        return $to->subYearNoOverflow();
    }

    /** Último día del periodo entero del año anterior (la referencia de la gráfica por meses). */
    public function previousFullTo(): CarbonImmutable
    {
        return $this->filters->to->subYearNoOverflow();
    }

    /**
     * La query de la URL: la de la barra más los servicios.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return [
            ...$this->filters->toQuery(),
            ...($this->services !== [] ? ['servicio' => array_map(fn (BillingService $service): string => $service->value, $this->services)] : []),
        ];
    }
}
