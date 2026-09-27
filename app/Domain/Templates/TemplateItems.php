<?php

namespace App\Domain\Templates;

use App\Models\ProjectTemplate;
use Illuminate\Support\Collection;

/**
 * Plantillas para la interfaz (D-058): filas del listado de /admin/plantillas y opciones de los
 * selectores («Desde plantilla» al crear un proyecto y «Aplicar plantilla» en Ajustes), con sus
 * cifras (tareas, subtareas, hitos, dependencias y duración) calculadas de la estructura. Tipos en
 * resources/js/types/templates.ts (TemplateRow y TemplateOption).
 */
final class TemplateItems
{
    /**
     * Plantillas que se pueden aplicar: activas y fuera de la papelera, por nombre.
     *
     * @return list<array{id: int, name: string, description: string|null, stats: array{tasks: int, subtasks: int, milestones: int, dependencies: int, duration_days: int}}>
     */
    public function options(): array
    {
        return array_values(ProjectTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'structure'])
            ->map(fn (ProjectTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'stats' => ProjectTemplateService::stats($template->structure),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, ProjectTemplate>  $templates
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $templates): array
    {
        return array_values($templates->map(fn (ProjectTemplate $template): array => [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'is_active' => $template->is_active,
            'stats' => ProjectTemplateService::stats($template->structure),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'deleted_at' => $template->deleted_at?->toIso8601String(),
        ])->all());
    }
}
