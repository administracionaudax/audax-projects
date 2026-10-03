<?php

namespace App\Domain\Tasks;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A quién se puede mencionar en una tarea (descripción y comentarios, SPEC §6): solo internos
 * activos. Colaboradores externos (D-134): a uno solo se le menciona en las tareas de los
 * proyectos de los que es miembro, y uno solo menciona a los miembros del proyecto.
 * Lo usan TaskWriter (descripción) y TaskCommentController (comentarios).
 */
final class TaskMentions
{
    /**
     * @param  list<int>  $ids  ids citados en el HTML saneado (RichText::mentionedUserIds)
     * @return list<int> los que se guardan y avisan, ordenados
     */
    public function mentionable(array $ids, int $projectId, User $author): array
    {
        if ($ids === []) {
            return [];
        }

        $members = DB::table('project_members')->select('user_id')->where('project_id', $projectId);

        return array_values(User::query()->whereKey($ids)->active()->internal()
            ->when(
                $author->isCollaborator(),
                fn (Builder $query) => $query->whereIn('id', $members),
                fn (Builder $query) => $query->seeingProject($projectId),
            )
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->all());
    }
}
