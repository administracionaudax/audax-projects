<?php

namespace App\Domain\Weeklies\Suggestions;

use App\Http\Resources\Tasks\AttachmentResource;
use App\Models\Attachment;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionStatusEvent;
use App\Models\SuggestionVote;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Support\Collection;

/**
 * Forma JSON de las sugerencias (contrato de resources/js/types/weeklies.ts, «Sugerencias»): los
 * tableros y categorías, una sugerencia en el feed o el roadmap, su detalle con la actividad
 * (comentarios anidados y cambios de estado) y sus adjuntos con URL firmada.
 */
final class SuggestionPresenter
{
    /** Caracteres de la vista previa del feed (WeeklySync: 180). */
    public const int PREVIEW = 180;

    /**
     * @return array{id: int, name: string, avatar: string|null, department_id: int|null, is_active: bool}
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar_url,
            'department_id' => $user->department_id,
            'is_active' => $user->is_active,
        ];
    }

    /**
     * @param  array<int, int>  $boardCounts  board_id → sugerencias
     * @param  array<int, int>  $categoryCounts  category_id → sugerencias
     * @return array<string, mixed>
     */
    public static function board(SuggestionBoard $board, array $boardCounts = [], array $categoryCounts = []): array
    {
        return [
            'id' => $board->id,
            'name' => $board->name,
            'slug' => $board->slug,
            'description' => $board->description,
            'position' => $board->position,
            'is_active' => $board->is_active,
            'post_count' => $boardCounts[$board->id] ?? 0,
            'categories' => $board->categories->map(fn (SuggestionCategory $category): array => self::category($category, $categoryCounts))->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<string, mixed>
     */
    public static function category(SuggestionCategory $category, array $counts = []): array
    {
        return [
            'id' => $category->id,
            'suggestion_board_id' => $category->suggestion_board_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'position' => $category->position,
            'is_active' => $category->is_active,
            'post_count' => $counts[$category->id] ?? 0,
        ];
    }

    /**
     * Una sugerencia del feed o del roadmap (author y category cargados).
     *
     * @param  array<int, true>  $votedIds
     * @return array<string, mixed>
     */
    public static function post(SuggestionPost $post, array $votedIds): array
    {
        return [
            'id' => $post->id,
            'suggestion_board_id' => $post->suggestion_board_id,
            'suggestion_category_id' => $post->suggestion_category_id,
            'category' => $post->category === null ? null : ['id' => $post->category->id, 'name' => $post->category->name, 'slug' => $post->category->slug],
            'author' => self::user($post->author),
            'title' => $post->title,
            'slug' => $post->slug,
            'preview' => RichText::toPlainText($post->body, self::PREVIEW),
            'status' => $post->status->value,
            'position' => $post->position,
            'vote_count' => $post->vote_count,
            'comment_count' => $post->comment_count,
            'voted_by_me' => isset($votedIds[$post->id]),
            'last_activity_at' => $post->last_activity_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * El detalle: el cuerpo, los adjuntos, quién ha votado, los comentarios en árbol y los cambios
     * de estado (todo cargado de antemano por SuggestionQueries::detail).
     *
     * @return array<string, mixed>
     */
    public static function detail(SuggestionPost $post, User $viewer): array
    {
        /** @var Collection<int, SuggestionVote> $votes */
        $votes = $post->votes;
        /** @var Collection<int, SuggestionComment> $comments */
        $comments = $post->comments;
        $byParent = $comments->groupBy(fn (SuggestionComment $comment): int => $comment->parent_id ?? 0);

        return [
            ...self::post($post, $votes->contains('user_id', $viewer->id) ? [$post->id => true] : []),
            'body' => $post->body,
            'board' => ['id' => $post->board->id, 'name' => $post->board->name, 'slug' => $post->board->slug],
            'attachments' => self::attachments($post->attachments),
            'voters' => $votes->map(fn (SuggestionVote $vote): array => self::user($vote->user))->values()->all(),
            'comments' => self::tree($byParent, 0),
            'status_events' => $post->statusEvents->map(fn (SuggestionStatusEvent $event): array => [
                'id' => $event->id,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status->value,
                'note' => $event->note,
                'changed_by' => $event->changer === null ? null : self::user($event->changer),
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @return list<array{id: int, name: string, mime: string, size: int, is_image: bool, url: string, thumbnail_url: string|null}>
     */
    public static function attachments(Collection $attachments): array
    {
        return array_values($attachments->sortBy('id')->map(fn (Attachment $attachment): array => [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'is_image' => $attachment->isPreviewableImage(),
            'url' => AttachmentResource::downloadUrl($attachment),
            'thumbnail_url' => AttachmentResource::thumbnailUrl($attachment),
        ])->all());
    }

    /**
     * @param  Collection<int, Collection<int, SuggestionComment>>  $byParent
     * @return list<array<string, mixed>>
     */
    private static function tree(Collection $byParent, int $parent): array
    {
        $children = $byParent->get($parent) ?? collect();

        return array_values($children->sortBy([['created_at', 'asc'], ['id', 'asc']])->map(fn (SuggestionComment $comment): array => [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'author' => self::user($comment->author),
            'body' => $comment->body,
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'attachments' => self::attachments($comment->attachments),
            'reactions' => $comment->reactions->sortBy('id')->map(fn (SuggestionCommentReaction $reaction): array => [
                'reaction' => $reaction->reaction->value,
                'user' => self::user($reaction->user),
            ])->values()->all(),
            'replies' => self::tree($byParent, $comment->id),
        ])->all());
    }
}
