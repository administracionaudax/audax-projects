<?php

namespace App\Http\Resources\Clients;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Cliente en el listado /clientes: ClientResource más sus proyectos activos y las horas del mes
 * (totales agregados, visibles para todos los internos). Contrato: resources/js/types/clients.ts
 * (ClientListItem). El controlador carga active_projects_count, month_minutes y, con la Weekly,
 * last_report_at y satisfaction_previous con subconsultas, y kind_badges.
 *
 * @mixin Client
 */
class ClientRowResource extends ClientResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'active_projects_count' => (int) $this->resource->getAttribute('active_projects_count'),
            'month_minutes' => (int) $this->resource->getAttribute('month_minutes'),
            // La cartera de la Weekly (Fase 10, F-096, F-120 y F-124): el último reporte, la tendencia
            // de la satisfacción frente al cierre anterior y las insignias por tipo de proyecto.
            'last_report_at' => $this->lastReportAt(),
            'satisfaction_trend' => $this->resource->getAttribute('satisfaction_previous') === null
                ? null
                : $this->resource->satisfaction_score - (int) $this->resource->getAttribute('satisfaction_previous'),
            'kind_badges' => $this->resource->getAttribute('kind_badges') ?? [],
            // Responsable y equipo (10.9b, D-232): solo con la Weekly (ClientPortfolioTeams).
            'portfolio_team' => $this->resource->getAttribute('portfolio_team'),
        ];
    }

    private function lastReportAt(): ?string
    {
        $value = $this->resource->getAttribute('last_report_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601String();
    }
}
