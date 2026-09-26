<?php

namespace App\Domain\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Archivar y recuperar proyectos (D-037: los proyectos no se borran, se archivan). Al recuperar
 * uno, vuelve al estado que tenía antes de archivarlo (según la auditoría) o, si no consta, a
 * «Activo».
 */
final class ProjectArchiver
{
    public function archive(Project $project): void
    {
        if ($project->status === ProjectStatus::Archived) {
            return;
        }

        $project->status = ProjectStatus::Archived;
        $project->save();
    }

    public function unarchive(Project $project): void
    {
        if ($project->status !== ProjectStatus::Archived) {
            return;
        }

        $project->status = $this->statusBeforeArchiving($project);
        $project->save();
    }

    public function statusBeforeArchiving(Project $project): ProjectStatus
    {
        $changes = Activity::query()
            ->forSubject($project)
            ->where('event', 'updated')
            ->latest('id')
            ->limit(50)
            ->pluck('attribute_changes');

        foreach ($changes as $change) {
            $data = $change instanceof Collection ? $change->all() : [];
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $old = is_array($data['old'] ?? null) ? $data['old'] : [];

            if (($attributes['status'] ?? null) === ProjectStatus::Archived->value) {
                $previous = is_string($old['status'] ?? null) ? ProjectStatus::tryFrom($old['status']) : null;

                return $previous !== null && $previous !== ProjectStatus::Archived ? $previous : ProjectStatus::Active;
            }
        }

        return ProjectStatus::Active;
    }
}
