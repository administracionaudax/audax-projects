<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\SuggestionComment;
use App\Models\User;
use App\Support\RichText;

/**
 * Tus comentarios en las sugerencias (10.7, D-211): en qué sugerencia, si responden a otro, el texto
 * y sus adjuntos (por su nombre).
 */
final class SuggestionCommentsSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'sugerencias-comentarios';
    }

    protected function textKey(): string
    {
        return 'suggestion_comments';
    }

    protected function columnKeys(): array
    {
        return ['id', 'post', 'reply_to', 'body', 'attachments', 'created_at', 'edited_at'];
    }

    public function rows(User $user): iterable
    {
        $comments = SuggestionComment::query()
            ->where('author_id', $user->id)
            ->with(['post:id,title', 'attachments:id,attachable_type,attachable_id,original_name'])
            ->lazyById(self::CHUNK);

        foreach ($comments as $comment) {
            yield [
                'id' => $comment->id,
                'post' => $comment->post->title,
                'reply_to' => $comment->parent_id,
                'body' => RichText::toPlainText($comment->body),
                'attachments' => $comment->attachments->pluck('original_name')->implode(', '),
                'created_at' => self::instant($comment->created_at),
                'edited_at' => self::instant($comment->edited_at),
            ];
        }
    }
}
