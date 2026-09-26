<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\UserSummaryResource;
use App\Models\CommentReaction;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Comentario del panel (contrato: resources/js/types/tasks.ts, TaskCommentItem). El cuerpo es HTML
 * ya saneado en el servidor (App\Support\RichText). Cargar antes author, reactions.user y
 * attachments.uploader. Editar: solo su autor; borrar: su autor o un admin (TaskCommentPolicy).
 */
final class TaskCommentResource
{
    /**
     * @param  Collection<int, TaskComment>  $comments
     * @return list<array<string, mixed>>
     */
    public static function listFor(Collection $comments, User $viewer, bool $canManageProject): array
    {
        $isAdmin = $viewer->isAdmin();

        return array_values($comments->map(fn (TaskComment $comment): array => [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->author === null ? null : Plain::of(UserSummaryResource::make($comment->author)),
            'created_at' => $comment->created_at?->toIso8601ZuluString(),
            'edited_at' => $comment->edited_at?->toIso8601ZuluString(),
            'can_update' => $comment->user_id === $viewer->id,
            'can_delete' => $comment->user_id === $viewer->id || $isAdmin,
            'reactions' => self::reactions($comment, $viewer),
            'attachments' => AttachmentResource::listFor($comment->attachments, $viewer, $canManageProject),
        ])->all());
    }

    /**
     * Reacciones agrupadas por emoji, en el orden de CommentReaction::EMOJIS.
     *
     * @return list<array{emoji: string, count: int, reacted: bool, users: list<string>}>
     */
    private static function reactions(TaskComment $comment, User $viewer): array
    {
        $grouped = $comment->reactions->groupBy('emoji');
        $result = [];

        foreach (CommentReaction::EMOJIS as $emoji) {
            /** @var Collection<int, CommentReaction>|null $reactions */
            $reactions = $grouped->get($emoji);

            if ($reactions === null || $reactions->isEmpty()) {
                continue;
            }

            $result[] = [
                'emoji' => $emoji,
                'count' => $reactions->count(),
                'reacted' => $reactions->contains('user_id', $viewer->id),
                'users' => array_values($reactions->map(fn (CommentReaction $reaction): string => $reaction->user->name)->all()),
            ];
        }

        return $result;
    }
}
