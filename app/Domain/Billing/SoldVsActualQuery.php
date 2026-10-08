<?php

namespace App\Domain\Billing;

use App\Domain\Reports\ReportFilters;
use App\Enums\SaleKind;

/**
 * Filtros del informe «Vendido frente a real» (Fase 12, D-390): el periodo y los clientes de la barra
 * de informes (ReportFilters: ?periodo=, ?desde=, ?hasta=, ?cliente[]=) más el tipo de venta
 * (?venta[]=bolsa|precio_cerrado|fee|horas) y el responsable (?responsable=id, el gestor principal
 * del proyecto). Las fichas de proyecto, cliente y bolsa fijan el suyo.
 */
final readonly class SoldVsActualQuery
{
    /**
     * @param  list<SaleKind>  $kinds
     * @param  list<int>  $projectIds
     * @param  list<int>  $bankIds
     */
    public function __construct(
        public ReportFilters $filters,
        public array $kinds = [],
        public ?int $managerId = null,
        public array $projectIds = [],
        public array $bankIds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $filters = ReportFilters::fromQuery($query)->withoutComparison();

        $kinds = [];
        foreach ((array) ($query['venta'] ?? []) as $value) {
            $kind = is_string($value) ? SaleKind::tryFrom($value) : null;
            if ($kind !== null && ! in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        $manager = $query['responsable'] ?? null;
        $managerId = is_numeric($manager) && (int) $manager > 0 ? (int) $manager : null;

        return new self($filters, $kinds, $managerId);
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<int>  $bankIds
     */
    public function fixed(array $projectIds = [], array $bankIds = []): self
    {
        return new self($this->filters, $this->kinds, $this->managerId, $projectIds, $bankIds);
    }

    /**
     * La query de la URL: la de la barra más venta y responsable.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return [
            ...$this->filters->toQuery(),
            ...($this->kinds !== [] ? ['venta' => array_map(fn (SaleKind $kind): string => $kind->value, $this->kinds)] : []),
            ...($this->managerId !== null ? ['responsable' => $this->managerId] : []),
        ];
    }

    public function wants(SaleKind $kind): bool
    {
        return $this->kinds === [] || in_array($kind, $this->kinds, true);
    }
}
