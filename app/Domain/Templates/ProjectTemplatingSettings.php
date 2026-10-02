<?php

namespace App\Domain\Templates;

use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Support\LocalTime;

/**
 * Sección «Plantilla» de los Ajustes del proyecto (D-058): plantillas activas que se pueden
 * aplicar (añaden tareas sin tocar las que hay), las bolsas abiertas si el proyecto es de bolsas,
 * el inicio por defecto (el del proyecto o hoy) y cuántas tareas tiene el proyecto para «Guardar
 * como plantilla». Se envía como prop diferida (`templating`) de projects/settings.
 * Tipo en resources/js/types/templates.ts (ProjectTemplatingSettings).
 */
final class ProjectTemplatingSettings
{
    public function __construct(private readonly TemplateItems $items) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Project $project): array
    {
        return [
            'templates' => $this->items->options(),
            'banks' => $project->usesHourBanks()
                ? array_values(HourBank::query()->where('project_id', $project->id)->open()->orderBy('start_date')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name])
                    ->all())
                : [],
            'uses_hour_banks' => $project->usesHourBanks(),
            'default_start' => $project->start_date?->toDateString() ?? LocalTime::todayString(),
            'task_count' => $project->tasks()->count(),
            'archived' => $project->status === ProjectStatus::Archived,
            'max_tasks' => ProjectTemplateService::MAX_TASKS,
        ];
    }
}
