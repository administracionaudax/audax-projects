<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncDump;
use App\Domain\Import\WeeklySync\WeeklySyncFiles;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncText;
use App\Domain\Tasks\AttachmentStorage;
use App\Enums\SuggestionReaction;
use App\Enums\SuggestionStatus;
use App\Models\Attachment;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionStatusEvent;
use App\Models\SuggestionVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sugerencias (D-149 y D-219): tableros, categorías, propuestas con sus votos, comentarios (con su
 * árbol), reacciones, cambios de estado y adjuntos. El tablero de WeeklySync que tiene la categoría
 * «bugs» es el de Audax que la tiene (el precargado «Sugerencias»): así «Reportar un bug» sigue
 * funcionando con todo junto. El Markdown con menciones pasa a HTML de RichText con el id de Audax.
 * Al final se recuentan votos y comentarios y se ordena el roadmap por la última actividad.
 */
final class SuggestionsStage
{
    public function run(WeeklySyncContext $context): void
    {
        DB::transaction(function () use ($context): void {
            $this->boards($context);
            $this->posts($context);
            $this->votes($context);
            $this->comments($context);
            $this->reactions($context);
            $this->events($context);
            $this->attachments($context);
            $this->recount($context);
        });
    }

    private function boards(WeeklySyncContext $context): void
    {
        $categories = $context->rows('suggestion_categories');
        $withBugs = [];
        foreach ($categories as $row) {
            if (WeeklySyncContext::str($row['slug'] ?? '') === SuggestionCategory::BUGS_SLUG) {
                $withBugs[WeeklySyncContext::id($row['board_id'] ?? null)] = true;
            }
        }
        $audaxBugsBoard = SuggestionCategory::query()->where('slug', SuggestionCategory::BUGS_SLUG)->orderBy('id')->value('suggestion_board_id');

        foreach ($context->rows('suggestion_boards') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $slug = Str::limit(Str::slug(WeeklySyncContext::str($row['slug'] ?? '')) ?: 'tablero', 80, '');
            $local = $context->refs->find('board', $id);
            $board = $local !== null ? SuggestionBoard::query()->find($local) : null;
            // Solo se rellenan los tableros que creó la importación; los de Audax solo se usan.
            $imported = $board !== null && $context->refs->find('board_created', $id) === $board->id;

            if ($board === null && isset($withBugs[$id]) && $audaxBugsBoard !== null) {
                $board = SuggestionBoard::query()->find((int) $audaxBugsBoard);
            }
            $board ??= SuggestionBoard::query()->where('slug', $slug)->first();

            if ($board !== null && ! $imported) {
                // Un tablero de Audax: solo se usa, no se cambia.
                $context->report->count('suggestion_boards', Report::UNCHANGED);
            } else {
                $created = $board === null;
                $board ??= new SuggestionBoard(['slug' => $slug]);
                $board->fill([
                    'name' => WeeklySyncText::plain($row['name'] ?? '', 255) ?: 'Sugerencias',
                    'description' => self::rename(WeeklySyncContext::nullableStr($row['description'] ?? null)),
                    'position' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => ($row['is_active'] ?? true) === true,
                    'created_by' => $context->user($row['created_by'] ?? null),
                ]);
                $this->save($context, 'suggestion_boards', $board, $created, $row);
                $context->refs->put('board_created', $id, 'suggestion_board', $board->id);
            }

            $context->refs->put('board', $id, 'suggestion_board', $board->id);
            $context->boards[$id] = $board->id;
        }

        foreach ($categories as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $board = $context->boards[WeeklySyncContext::id($row['board_id'] ?? null)] ?? null;

            if ($board === null) {
                $context->report->skip('suggestion_categories', 'Categorías de tableros que no se importan');

                continue;
            }

            $slug = Str::limit(Str::slug(WeeklySyncContext::str($row['slug'] ?? '')) ?: 'categoria', 80, '');
            $local = $context->refs->find('category', $id);
            $category = $local !== null ? SuggestionCategory::query()->find($local) : null;
            $imported = $category !== null && $context->refs->find('category_created', $id) === $category->id;
            $category ??= SuggestionCategory::query()->where(['suggestion_board_id' => $board, 'slug' => $slug])->first();

            if ($category !== null && ! $imported) {
                $context->report->count('suggestion_categories', Report::UNCHANGED);
            } else {
                $created = $category === null;
                $category ??= new SuggestionCategory(['suggestion_board_id' => $board, 'slug' => $slug]);
                $category->fill([
                    'name' => WeeklySyncText::plain($row['name'] ?? '', 255) ?: 'General',
                    'description' => self::rename(WeeklySyncContext::nullableStr($row['description'] ?? null)),
                    'position' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => ($row['is_active'] ?? true) === true,
                    'created_by' => $context->user($row['created_by'] ?? null),
                ]);
                $this->save($context, 'suggestion_categories', $category, $created, $row);
                $context->refs->put('category_created', $id, 'suggestion_category', $category->id);
            }

            $context->refs->put('category', $id, 'suggestion_category', $category->id);
            $context->categories[$id] = $category->id;
        }
    }

    private function posts(WeeklySyncContext $context): void
    {
        foreach ($context->rows('suggestion_posts') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $board = $context->boards[WeeklySyncContext::id($row['board_id'] ?? null)] ?? null;
            $author = $context->user($row['author_id'] ?? null);
            $body = $this->rich($context, $row['body'] ?? null);

            if ($board === null || $author === null || $body === null) {
                $context->report->skip('suggestion_posts', 'Sugerencias de personas o tableros que no se importan, o vacías');

                continue;
            }

            $local = $context->refs->find('post', $id);
            $post = $local !== null ? SuggestionPost::query()->find($local) : null;
            $created = $post === null;
            $post ??= new SuggestionPost;

            $title = WeeklySyncText::plain($row['title'] ?? '', 255) ?: 'Sugerencia';
            $slug = $post->slug ?? $this->slug($board, WeeklySyncContext::str($row['slug'] ?? '') ?: $title, $id);
            $status = SuggestionStatus::tryFrom(WeeklySyncContext::str($row['status'] ?? '')) ?? SuggestionStatus::Open;

            $post->fill([
                'suggestion_board_id' => $board,
                'suggestion_category_id' => $context->categories[WeeklySyncContext::id($row['category_id'] ?? null)] ?? null,
                'author_id' => $author,
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'status' => $status,
                'last_activity_at' => WeeklySyncContext::instant($row['last_activity_at'] ?? null) ?? WeeklySyncContext::instant($row['created_at'] ?? null),
            ]);

            $this->save($context, 'suggestion_posts', $post, $created, $row);
            $context->refs->put('post', $id, 'suggestion_post', $post->id);
            $context->posts[$id] = $post->id;
        }
    }

    private function votes(WeeklySyncContext $context): void
    {
        foreach ($context->rows('suggestion_votes') as $row) {
            $post = $context->posts[WeeklySyncContext::id($row['post_id'] ?? null)] ?? null;
            $user = $context->user($row['user_id'] ?? null);

            if ($post === null || $user === null) {
                $context->report->skip('suggestion_votes', 'Votos de personas o sugerencias que no se importan');

                continue;
            }

            $vote = SuggestionVote::query()->firstOrNew(['suggestion_post_id' => $post, 'user_id' => $user]);

            if ($vote->exists) {
                $context->report->count('suggestion_votes', Report::UNCHANGED);

                continue;
            }

            if (($at = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
                $vote->created_at = $at;
            }
            $vote->save();
            $context->report->count('suggestion_votes', Report::CREATED);
        }
    }

    private function comments(WeeklySyncContext $context): void
    {
        $rows = $context->rows('suggestion_comments');
        // Los padres antes que sus respuestas.
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        foreach ($rows as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $post = $context->posts[WeeklySyncContext::id($row['post_id'] ?? null)] ?? null;
            $author = $context->user($row['author_id'] ?? null);
            $parentId = WeeklySyncContext::id($row['parent_comment_id'] ?? null);
            $parent = $parentId !== '' ? ($context->comments[$parentId] ?? null) : null;
            $body = $this->rich($context, $row['body'] ?? null);

            if ($post === null || $author === null || $body === null || ($parentId !== '' && $parent === null)) {
                $context->report->skip('suggestion_comments', 'Comentarios de personas o sugerencias que no se importan, o vacíos');

                continue;
            }

            $local = $context->refs->find('comment', $id);
            $comment = $local !== null ? SuggestionComment::query()->find($local) : null;
            $created = $comment === null;
            $comment ??= new SuggestionComment;

            $createdAt = WeeklySyncContext::instant($row['created_at'] ?? null);
            $updatedAt = WeeklySyncContext::instant($row['updated_at'] ?? null);
            $comment->fill([
                'suggestion_post_id' => $post,
                'author_id' => $author,
                'parent_id' => $parent,
                'body' => $body,
                'edited_at' => $createdAt !== null && $updatedAt !== null && $updatedAt->diffInSeconds($createdAt, true) > 60 ? $updatedAt : null,
            ]);

            $this->save($context, 'suggestion_comments', $comment, $created, $row);
            $context->refs->put('comment', $id, 'suggestion_comment', $comment->id);
            $context->comments[$id] = $comment->id;
        }
    }

    private function reactions(WeeklySyncContext $context): void
    {
        foreach ($context->rows('suggestion_comment_reactions') as $row) {
            $comment = $context->comments[WeeklySyncContext::id($row['comment_id'] ?? null)] ?? null;
            $user = $context->user($row['user_id'] ?? null);
            $reaction = SuggestionReaction::tryFrom(WeeklySyncContext::str($row['reaction_key'] ?? ''));

            if ($comment === null || $user === null || $reaction === null) {
                $context->report->skip('suggestion_reactions', 'Reacciones de personas o comentarios que no se importan');

                continue;
            }

            $model = SuggestionCommentReaction::query()->firstOrNew(['suggestion_comment_id' => $comment, 'user_id' => $user]);
            $created = ! $model->exists;
            $model->reaction = $reaction;

            if ($created && ($at = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
                $model->created_at = $at;
            }

            $outcome = $created ? Report::CREATED : ($model->isDirty() ? Report::UPDATED : Report::UNCHANGED);
            if ($outcome !== Report::UNCHANGED) {
                $model->save();
            }
            $context->report->count('suggestion_reactions', $outcome);
        }
    }

    private function events(WeeklySyncContext $context): void
    {
        foreach ($context->rows('suggestion_status_events') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $post = $context->posts[WeeklySyncContext::id($row['post_id'] ?? null)] ?? null;
            $to = SuggestionStatus::tryFrom(WeeklySyncContext::str($row['to_status'] ?? ''));

            if ($post === null || $to === null) {
                $context->report->skip('suggestion_events', 'Cambios de estado de sugerencias que no se importan');

                continue;
            }

            $local = $context->refs->find('status_event', $id);
            $event = $local !== null ? SuggestionStatusEvent::query()->find($local) : null;
            $created = $event === null;
            $event ??= new SuggestionStatusEvent;
            $event->fill([
                'suggestion_post_id' => $post,
                'from_status' => SuggestionStatus::tryFrom(WeeklySyncContext::str($row['from_status'] ?? '')),
                'to_status' => $to,
                'note' => WeeklySyncContext::nullableStr($row['note'] ?? null),
                'changed_by' => $context->user($row['changed_by'] ?? null),
            ]);

            $this->save($context, 'suggestion_events', $event, $created, $row);
            $context->refs->put('status_event', $id, 'suggestion_status_event', $event->id);
        }
    }

    private function attachments(WeeklySyncContext $context): void
    {
        foreach (['suggestion_post_attachments' => 'post', 'suggestion_comment_attachments' => 'comment'] as $table => $kind) {
            foreach ($context->rows($table) as $row) {
                $id = WeeklySyncContext::id($row['id'] ?? null);
                $owner = $kind === 'post'
                    ? SuggestionPost::query()->find($context->posts[WeeklySyncContext::id($row['post_id'] ?? null)] ?? 0)
                    : SuggestionComment::query()->find($context->comments[WeeklySyncContext::id($row['comment_id'] ?? null)] ?? 0);
                $source = WeeklySyncDump::storagePath($row['storage_path'] ?? null, WeeklySyncDump::SUGGESTIONS_BUCKET);

                if ($owner === null || $source === null) {
                    $context->report->skip('suggestion_attachments', 'Adjuntos de sugerencias o comentarios que no se importan');

                    continue;
                }

                $postId = $owner instanceof SuggestionPost ? $owner->id : $owner->suggestion_post_id;
                $directory = $kind === 'post' ? "attachments/suggestions/{$postId}" : "attachments/suggestions/{$postId}/comments";
                $copy = $context->files->copy(WeeklySyncDump::SUGGESTIONS_BUCKET, $source, "{$directory}/weeklysync-{$id}.".WeeklySyncFiles::extension($source, 'bin'));

                if ($copy === null) {
                    $context->report->skip('suggestion_attachments', 'Adjuntos que no están en el volcado');

                    continue;
                }

                // Con vídeos (grabaciones de pantalla de un bug, D-235).
                if (! in_array($copy['mime'], AttachmentStorage::allowedMimes(videos: true), true)) {
                    $context->report->skip('suggestion_attachments', 'Adjuntos de un tipo que Audax no admite');

                    continue;
                }

                $local = $context->refs->find($kind.'_attachment', $id);
                $attachment = $local !== null ? Attachment::query()->find($local) : null;
                $created = $attachment === null;
                $attachment ??= new Attachment;
                $attachment->fill([
                    'project_id' => null,
                    'user_id' => $context->user($row['uploaded_by'] ?? null),
                    'disk' => $copy['disk'],
                    'path' => $copy['path'],
                    'original_name' => Str::limit(WeeklySyncContext::nullableStr($row['file_name'] ?? null) ?? basename($source), 255, ''),
                    'mime' => $copy['mime'],
                    'size' => $copy['size'],
                ]);
                $attachment->attachable()->associate($owner);

                $this->save($context, 'suggestion_attachments', $attachment, $created, $row);
                $context->refs->put($kind.'_attachment', $id, 'attachment', $attachment->id);
            }
        }
    }

    /**
     * Votos y comentarios recontados; el roadmap de lo importado, por la última actividad, detrás de
     * lo que ya estaba en Audax en cada estado.
     */
    private function recount(WeeklySyncContext $context): void
    {
        $imported = array_values(array_unique($context->posts));

        if ($imported === []) {
            return;
        }

        foreach (SuggestionPost::query()->whereIn('id', $imported)->withCount(['votes', 'comments'])->get() as $post) {
            $post->vote_count = (int) $post->getAttribute('votes_count');
            $post->comment_count = (int) $post->getAttribute('comments_count');
            if ($post->isDirty()) {
                $post->saveQuietly();
            }
        }

        foreach (SuggestionStatus::cases() as $status) {
            $base = (int) SuggestionPost::query()->where('status', $status->value)->whereNotIn('id', $imported)->max('position');
            $posts = SuggestionPost::query()->where('status', $status->value)->whereIn('id', $imported)
                ->orderByDesc('last_activity_at')->orderBy('id')->get(['id', 'position']);

            foreach ($posts as $index => $post) {
                $position = $base + $index + 1;
                if ($post->position !== $position) {
                    SuggestionPost::query()->whereKey($post->id)->update(['position' => $position]);
                }
            }
        }
    }

    private function rich(WeeklySyncContext $context, mixed $text): ?string
    {
        return WeeklySyncText::rich(
            is_string($text) ? $text : null,
            fn (string $weeklySyncId): ?int => $context->user($weeklySyncId),
            fn (int $userId): ?string => User::query()->whereKey($userId)->value('name'),
        );
    }

    private function slug(int $board, string $text, string $id): string
    {
        $slug = Str::limit(Str::slug($text) ?: 'sugerencia', 140, '');

        if (SuggestionPost::query()->where(['suggestion_board_id' => $board, 'slug' => $slug])->exists()) {
            $slug .= '-'.substr($id, 0, 8);
        }

        return $slug;
    }

    private static function rename(?string $text): ?string
    {
        return $text !== null ? str_replace('WeeklySync', 'Audax Proyectos', $text) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function save(WeeklySyncContext $context, string $type, Model $model, bool $created, array $row): void
    {
        if ($created && ($at = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
            $model->setAttribute('created_at', $at);
        }

        $outcome = $created ? Report::CREATED : ($model->isDirty() ? Report::UPDATED : Report::UNCHANGED);

        if ($outcome !== Report::UNCHANGED) {
            $model->save();
        }

        $context->report->count($type, $outcome);
    }
}
