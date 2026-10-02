<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\TaskComment;
use App\Models\User;
use App\Support\RichText;

/**
 * Comentarios propios en tareas, en texto plano, con la tarea y el proyecto. Incluye los borrados
 * (siguen guardados en la papelera) con su fecha de borrado.
 */
final class TaskCommentsSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'comentarios';
    }

    protected function textKey(): string
    {
        return 'comments';
    }

    protected function columnKeys(): array
    {
        return ['id', 'task_id', 'task', 'project', 'body', 'created_at', 'edited_at', 'deleted_at'];
    }

    public function rows(User $user): iterable
    {
        $comments = TaskComment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->with([
                'task' => fn ($task) => $task->withTrashed()->select(['id', 'title', 'project_id']),
                'task.project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name']),
            ])
            ->lazyById(self::CHUNK);

        foreach ($comments as $comment) {
            $task = $comment->task;

            yield [
                'id' => $comment->id,
                'task_id' => $comment->task_id,
                'task' => $task->title,
                'project' => "{$task->project->code} · {$task->project->name}",
                'body' => RichText::toPlainText($comment->body),
                'created_at' => self::instant($comment->created_at),
                'edited_at' => self::instant($comment->edited_at),
                'deleted_at' => self::instant($comment->deleted_at),
            ];
        }
    }
}
