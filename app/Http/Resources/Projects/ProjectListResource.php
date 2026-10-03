<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Fila del listado de proyectos: ProjectResource más el consumo agregado de sus bolsas abiertas
 * (activas y agotadas), si es un proyecto de bolsas. Contrato: resources/js/types/projects.ts
 * (ProjectListItem). El controlador carga client, owner y los agregados open_banks_*. Un
 * colaborador externo no ve el consumo de las bolsas (D-134): hour_banks llega a null.
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
        $viewer = $request->user();
        $hidesBanks = $viewer instanceof User && $viewer->isCollaborator();

        return [
            ...parent::toArray($request),
            'hour_banks' => $this->usesHourBanks() && ! $hidesBanks ? [
                'open_count' => (int) ($attributes['open_banks_count'] ?? 0),
                'total_minutes' => (int) ($attributes['open_banks_total'] ?? 0),
                'consumed_minutes' => (int) ($attributes['open_banks_consumed'] ?? 0),
                'overage_minutes' => (int) ($attributes['open_banks_overage'] ?? 0),
            ] : null,
        ];
    }
}
