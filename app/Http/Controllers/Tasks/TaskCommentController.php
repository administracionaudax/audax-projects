<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Tasks\TaskNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreCommentRequest;
use App\Http\Requests\Tasks\UpdateCommentRequest;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\Tasks\TaskMentionedNotification;
use App\Support\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

/**
 * Comentarios de tareas (SPEC §6): con menciones y adjuntos. Comenta cualquier interno (D-031);
 * cada uno edita los suyos y los borra él o un admin (TaskCommentPolicy). El cuerpo se sanea
 * SIEMPRE en el servidor (App\Support\RichText) y las menciones salen del HTML saneado.
 */
class TaskCommentController extends Controller
{
    public function __construct(
        private readonly TaskNotifier $notifier,
        private readonly AttachmentStorage $storage,
    ) {}

    public function store(StoreCommentRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('comment', $task);

        /** @var User $user */
        $user = $request->user();
        $body = RichText::sanitize($request->string('body')->toString());
        $mentioned = $this->mentionable(RichText::mentionedUserIds($body));
        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
        $stored = [];

        try {
            $comment = DB::transaction(function () use ($task, $user, $body, $mentioned, $files, &$stored): TaskComment {
                $comment = $task->comments()->create([
                    'user_id' => $user->id,
                    'body' => $body ?? '',
                    'mentioned_user_ids' => $mentioned,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $comment, $task->project_id, $user);
                }

                return $comment;
            });
        } catch (Throwable $exception) {
            $this->discard($stored);

            throw $exception;
        }

        $task->loadMissing('project');
        $this->notifier->capture(function () use ($task, $user, $comment, $mentioned, $body): void {
            $notified = $this->notifier->mentioned($task, $user, $mentioned, TaskMentionedNotification::IN_COMMENT, $body);
            $this->notifier->commented($task, $user, $comment, $notified);
        });

        return back();
    }

    public function update(UpdateCommentRequest $request, TaskComment $comment): RedirectResponse
    {
        Gate::authorize('update', $comment);

        /** @var User $user */
        $user = $request->user();
        $body = RichText::sanitize($request->string('body')->toString()) ?? '';
        $mentioned = $this->mentionable(RichText::mentionedUserIds($body));
        $previous = array_map('intval', $comment->mentioned_user_ids ?? []);
        // Un comentario de una tarea borrada ya no se edita (404).
        $task = $comment->task()->with('project')->firstOrFail();

        $comment->forceFill([
            'body' => $body,
            'mentioned_user_ids' => $mentioned,
            'edited_at' => now(),
        ])->save();

        $this->notifier->mentioned($task, $user, array_values(array_diff($mentioned, $previous)), TaskMentionedNotification::IN_COMMENT, $body);

        return back();
    }

    public function destroy(Request $request, TaskComment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        DB::transaction(function () use ($comment): void {
            foreach ($comment->attachments()->get() as $attachment) {
                $this->storage->delete($attachment);
            }

            $comment->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.comment_deleted')]);

        return back();
    }

    /**
     * Solo se guardan (y avisan) las menciones a internos activos.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function mentionable(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(User::query()->whereKey($ids)->active()->internal()->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->all());
    }

    /**
     * Si falla el alta, se borran los ficheros ya guardados (no quedan huérfanos en el disco).
     *
     * @param  list<Attachment>  $attachments
     */
    private function discard(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            rescue(fn () => $this->storage->delete($attachment), report: false);
        }
    }
}
