<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\AttachmentResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Archivos del proyecto (SPEC §6): todos los adjuntos de sus tareas y de sus comentarios
 * (los del chat llegan en la Fase 6), con filtros por tipo (?tipo=) y por tarea (?tarea_id=).
 * Los de tareas o comentarios borrados no aparecen.
 */
class ProjectFilesController extends Controller
{
    public const int PER_PAGE = 50;

    /**
     * Categorías del filtro por tipo → tipos MIME (Attachment::ALLOWED_MIMES).
     */
    public const array CATEGORIES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif'],
        'pdf' => ['application/pdf'],
        'document' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'spreadsheet' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv',
        ],
        'text' => ['text/plain', 'text/markdown'],
        'archive' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        /** @var User $user */
        $user = $request->user();
        $project->loadMissing(['client', 'owner']);
        // Los parámetros pueden llegar como arrays (?tipo[]=…): entonces se ignoran.
        $type = $request->query('tipo');
        $category = is_string($type) ? $type : '';
        $mimes = self::CATEGORIES[$category] ?? null;
        $category = $mimes === null ? null : $category;
        $taskParam = $request->query('tarea_id');
        $taskId = is_string($taskParam) && ctype_digit($taskParam) ? (int) $taskParam : null;

        $taskMorph = (new Task)->getMorphClass();
        $commentMorph = (new TaskComment)->getMorphClass();

        $attachments = Attachment::query()
            ->where('project_id', $project->id)
            // Solo adjuntos de tareas y comentarios vivos (un comentario de una tarea borrada, tampoco).
            ->whereHasMorph('attachable', [Task::class, TaskComment::class], function (Builder $query, string $type): void {
                if ($type === TaskComment::class) {
                    $query->whereHas('task');
                }
            })
            ->when($mimes !== null, fn (Builder $query) => $query->whereIn('mime', (array) $mimes))
            ->when($taskId !== null, fn (Builder $query) => $query->where(function (Builder $scope) use ($taskId, $taskMorph, $commentMorph): void {
                $scope->where(fn (Builder $tasks) => $tasks->where('attachable_type', $taskMorph)->where('attachable_id', $taskId))
                    ->orWhere(fn (Builder $comments) => $comments->where('attachable_type', $commentMorph)
                        ->whereIn('attachable_id', TaskComment::query()->select('id')->where('task_id', $taskId)));
            }))
            ->with(['uploader', 'attachable'])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // La tarea de cada adjunto de comentario (para enlazar al panel), en una sola consulta.
        $files = new EloquentCollection($attachments->items());
        $files->loadMorph('attachable', [TaskComment::class => ['task:id,title,project_id']]);

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->where(fn (Builder $query) => $query->whereHas('attachments')->orWhereHas('comments.attachments'))
            ->orderBy('title')
            ->get(['id', 'title']);

        return Inertia::render('projects/files', [
            'project' => Plain::of(ProjectResource::make($project)),
            'canManage' => $user->canManageProject($project),
            'files' => AttachmentResource::listFor($files, $user, $user->canManageProject($project)),
            'pagination' => [
                'current_page' => $attachments->currentPage(),
                'last_page' => $attachments->lastPage(),
                'total' => $attachments->total(),
                'prev_url' => $this->relative($attachments->previousPageUrl()),
                'next_url' => $this->relative($attachments->nextPageUrl()),
            ],
            'filters' => ['type' => $category, 'task' => $taskId],
            'categories' => array_keys(self::CATEGORIES),
            'tasks' => array_values($tasks->map(fn (Task $task): array => ['id' => $task->id, 'name' => $task->title])->all()),
        ]);
    }

    private function relative(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
