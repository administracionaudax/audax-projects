<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\SuggestionPost;
use App\Models\User;
use App\Support\RichText;

/**
 * Las sugerencias que has publicado en el centro de ayuda (Fase 10, 10.7, D-211), con su estado y
 * cuántos votos y comentarios tienen. Los adjuntos van por su nombre.
 */
final class SuggestionsSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'sugerencias';
    }

    protected function textKey(): string
    {
        return 'suggestions';
    }

    protected function columnKeys(): array
    {
        return ['id', 'board', 'category', 'title', 'body', 'status', 'votes', 'comments', 'attachments', 'created_at', 'updated_at'];
    }

    public function rows(User $user): iterable
    {
        $posts = SuggestionPost::query()
            ->where('author_id', $user->id)
            ->with(['board:id,name', 'category:id,name', 'attachments:id,attachable_type,attachable_id,original_name'])
            ->lazyById(self::CHUNK);

        foreach ($posts as $post) {
            yield [
                'id' => $post->id,
                'board' => $post->board->name,
                'category' => $post->category?->name,
                'title' => $post->title,
                'body' => RichText::toPlainText($post->body),
                'status' => $post->status->label(),
                'votes' => $post->vote_count,
                'comments' => $post->comment_count,
                'attachments' => $post->attachments->pluck('original_name')->implode(', '),
                'created_at' => self::instant($post->created_at),
                'updated_at' => self::instant($post->updated_at),
            ];
        }
    }
}
