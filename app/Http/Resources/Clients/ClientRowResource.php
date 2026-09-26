<?php

namespace App\Http\Resources\Clients;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Cliente en el listado /clientes: ClientResource más sus proyectos activos y las horas del mes
 * (totales agregados, visibles para todos los internos). Contrato: resources/js/types/clients.ts
 * (ClientListItem). El controlador carga active_projects_count y month_minutes con subconsultas.
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
        ];
    }
}
