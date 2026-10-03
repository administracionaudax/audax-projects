<?php

namespace App\Domain\Reports\Delivery;

use App\Domain\Reports\ReportFilters;
use Carbon\CarbonImmutable;

/**
 * Qué informe y con qué filtros (D-139): lo que hoy va en la URL de un dashboard. Se guarda en los
 * envíos programados y se regenera cada vez con los permisos de quien lo programó.
 */
final readonly class ReportRequest
{
    /**
     * @param  array<string, int|string>  $routeParams  p. ej. ['client' => 12] o ['project' => 3, 'hourBank' => 9]
     * @param  array<string, mixed>  $query  filtros de la URL (ReportFilters::toQuery() y los propios del informe)
     */
    public function __construct(
        public ReportKind $kind,
        public array $routeParams = [],
        public array $query = [],
    ) {}

    /**
     * Copia con el periodo resuelto para el día $today (D-141). Con Fixed, sin cambios.
     */
    public function resolvedFor(RelativePeriod $relative, CarbonImmutable $today): self
    {
        if ($relative === RelativePeriod::Fixed) {
            return $this;
        }

        $filters = ReportFilters::fromQuery(array_merge($this->query, ['fecha' => $today->toDateString()]), $today);

        if ($relative === RelativePeriod::Previous) {
            $filters = $filters->shifted(-1);
        }

        return new self($this->kind, $this->routeParams, array_merge($this->query, $filters->toQuery()));
    }

    /**
     * @return array{kind: string, route_params: array<string, int|string>, query: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'route_params' => $this->routeParams, 'query' => $this->query];
    }

    /**
     * @param  array{kind: string, route_params?: array<string, int|string>, query?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ReportKind::from($data['kind']), $data['route_params'] ?? [], $data['query'] ?? []);
    }
}
