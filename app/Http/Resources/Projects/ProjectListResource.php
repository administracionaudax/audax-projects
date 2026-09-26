<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Fila del listado de proyectos: ProjectResource más el consumo agregado de sus bolsas abiertas
 * (activas y agotadas), si es un proyecto de bolsas. Contrato: resources/js/types/projects.ts
 * (ProjectListItem). El controlador carga client, owner y los agregados open_banks_*.
 *
 * @mixin Project
 */
class ProjectListResource extends ProjectResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            ...parent::toArray($request),
            'hour_banks' => $this->usesHourBanks() ? [
                'open_count' => (int) ($attributes['open_banks_count'] ?? 0),
                'total_minutes' => (int) ($attributes['open_banks_total'] ?? 0),
                'consumed_minutes' => (int) ($attributes['open_banks_consumed'] ?? 0),
                'overage_minutes' => (int) ($attributes['open_banks_overage'] ?? 0),
            ] : null,
        ];
    }
}
