<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ToggleReactionRequest;
use App\Models\CommentReaction;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Reacciones a comentarios (SPEC §6): cada persona activa o quita cada emoji de la lista cerrada.
 * Puede reaccionar quien puede comentar la tarea (cualquier interno, D-031).
 */
class CommentReactionController extends Controller
{
    public function __invoke(ToggleReactionRequest $request, TaskComment $comment): RedirectResponse
    {
        Gate::authorize('comment', $comment->task()->firstOrFail());

        /** @var User $user */
        $user = $request->user();
        $emoji = $request->string('emoji')->toString();

        $existing = CommentReaction::query()
            ->where('task_comment_id', $comment->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return back();
        }

        try {
            CommentReaction::query()->create([
                'task_comment_id' => $comment->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Doble clic: la reacción ya estaba puesta.
        }

        return back();
    }
}
