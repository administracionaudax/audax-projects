<?php

namespace App\Domain\Weeklies\Suggestions;

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
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
use App\Notifications\Suggestions\SuggestionMentioned;
use App\Notifications\Suggestions\SuggestionReplied;
use App\Notifications\Suggestions\SuggestionStatusChanged;
use App\Support\RichText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Todo lo que cambia una sugerencia (F-161 a F-168), con las reglas de `helpSuggestions.ts`:
 * - crear (tablero y categoría activos, slug único en el tablero, estado open) y editar o borrar
 *   (autor o quien gestiona; al borrar se van sus adjuntos y los de sus comentarios del disco),
 * - votar alternando, un voto por persona (índice único) y `vote_count` recontado,
 * - comentar con respuestas anidadas, adjuntos y menciones; editar (autor) y borrar (autor o quien
 *   gestiona, con sus respuestas); `comment_count` recontado y `last_activity_at` al día,
 * - reaccionar a un comentario: una reacción por persona (la misma la quita, otra la cambia),
 * - el estado con nota oficial e historial (un cambio sin estado nuevo ni nota no hace nada) y el
 *   orden en el roadmap, que al cambiar de columna también cambia el estado,
 * - avisos (D-209): al autor si cambia el estado de su sugerencia; al autor de la sugerencia o del
 *   comentario al que se responde; a los mencionados. Nunca a quien lo hace, y una sola vez por persona.
 */
final class SuggestionWriter
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /**
     * @param  array{title: string, body: string, suggestion_board_id: int, suggestion_category_id: int|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(User $author, array $data, array $files): SuggestionPost
    {
        [$board, $category] = $this->taxonomy($data['suggestion_board_id'], $data['suggestion_category_id']);
        $stored = [];

        try {
            $post = DB::transaction(function () use ($author, $data, $board, $category, $files, &$stored): SuggestionPost {
                $post = SuggestionPost::query()->create([
                    'suggestion_board_id' => $board->id,
                    'suggestion_category_id' => $category?->id,
                    'author_id' => $author->id,
                    'title' => $data['title'],
                    'slug' => $this->uniqueSlug($board->id, $data['title']),
                    'body' => $data['body'],
                    'status' => SuggestionStatus::Open,
                    'last_activity_at' => now(),
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $post, null, $author);
                }

                return $post;
            });
        } catch (Throwable $exception) {
            $this->cleanup($stored);

            throw $exception;
        }

        $this->notifyMentions($post, $author, RichText::mentionedUserIds($post->body), SuggestionMentioned::IN_POST, $post->body);

        return $post;
    }

    /**
     * @param  array{title: string, body: string, suggestion_board_id: int, suggestion_category_id: int|null}  $data
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeAttachmentIds
     */
    public function update(SuggestionPost $post, User $editor, array $data, array $files, array $removeAttachmentIds): SuggestionPost
    {
        [$board, $category] = $this->taxonomy($data['suggestion_board_id'], $data['suggestion_category_id']);
        $before = RichText::mentionedUserIds($post->body);
        $stored = [];

        try {
            DB::transaction(function () use ($post, $editor, $data, $board, $category, $files, &$stored): void {
                $post->fill([
                    'suggestion_board_id' => $board->id,
                    'suggestion_category_id' => $category?->id,
                    'title' => $data['title'],
                    'body' => $data['body'],
                ]);

                if ($post->isDirty('suggestion_board_id') || $post->isDirty('title')) {
                    $post->slug = $this->uniqueSlug($board->id, $data['title'], $post->id);
                }

                $post->save();

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $post, null, $editor);
                }
            });
        } catch (Throwable $exception) {
            $this->cleanup($stored);

            throw $exception;
        }

        $this->removeAttachments($post, $removeAttachmentIds);

        // Solo avisa a quien se menciona por primera vez en esta edición.
        $this->notifyMentions($post, $editor, array_values(array_diff(RichText::mentionedUserIds($post->body), $before)), SuggestionMentioned::IN_POST, $post->body);

        return $post;
    }

    public function delete(SuggestionPost $post): void
    {
        $commentIds = $post->comments()->pluck('id')->all();
        $attachments = Attachment::query()
            ->where(fn ($query) => $query
                ->whereMorphedTo('attachable', $post)
                ->orWhere(fn ($q) => $q->where('attachable_type', (new SuggestionComment)->getMorphClass())->whereIn('attachable_id', $commentIds)))
            ->get();

        DB::transaction(function () use ($post, $attachments): void {
            foreach ($attachments as $attachment) {
                $this->storage->delete($attachment);
            }

            $post->delete();
        });
    }

    /** Alterna el voto de $user. Devuelve si ahora vota. */
    public function toggleVote(SuggestionPost $post, User $user): bool
    {
        return DB::transaction(function () use ($post, $user): bool {
            $deleted = SuggestionVote::query()->where('suggestion_post_id', $post->id)->where('user_id', $user->id)->delete();
            $voted = $deleted === 0;

            if ($voted) {
                try {
                    // En un punto de guardado: en PostgreSQL, la violación del índice único abortaría
                    // la transacción entera y el recuento de después fallaría.
                    DB::transaction(fn () => SuggestionVote::query()->create(['suggestion_post_id' => $post->id, 'user_id' => $user->id]));
                } catch (UniqueConstraintViolationException) {
                    // Dos clics a la vez: ya había voto.
                }
            }

            $this->recount($post);

            return $voted;
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function comment(SuggestionPost $post, User $author, string $body, ?int $parentId, array $files): SuggestionComment
    {
        $parent = null;

        if ($parentId !== null) {
            $parent = SuggestionComment::query()->where('suggestion_post_id', $post->id)->find($parentId);

            if ($parent === null) {
                throw ValidationException::withMessages(['parent_id' => __('help.suggestions.parent_missing')]);
            }
        }

        $stored = [];

        try {
            $comment = DB::transaction(function () use ($post, $author, $body, $parent, $files, &$stored): SuggestionComment {
                $comment = SuggestionComment::query()->create([
                    'suggestion_post_id' => $post->id,
                    'author_id' => $author->id,
                    'parent_id' => $parent?->id,
                    'body' => $body,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $comment, null, $author);
                }

                $this->recount($post, touch: true);

                return $comment;
            });
        } catch (Throwable $exception) {
            $this->cleanup($stored);

            throw $exception;
        }

        $mentioned = $this->notifyMentions($post, $author, RichText::mentionedUserIds($body), SuggestionMentioned::IN_COMMENT, $body);

        // A quién se responde: al autor del comentario padre o, si no hay padre, al de la sugerencia.
        $recipientId = $parent !== null ? $parent->author_id : $post->author_id;
        $context = $parent !== null ? SuggestionReplied::ON_COMMENT : SuggestionReplied::ON_POST;

        if ($recipientId !== $author->id && ! in_array($recipientId, $mentioned, true)) {
            $recipient = $this->recipients([$recipientId]);

            if ($recipient !== []) {
                Notification::send($recipient, new SuggestionReplied($post->id, $post->title, $author->name, $context, RichText::toPlainText($body, 160)));
            }
        }

        return $comment;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeAttachmentIds
     */
    public function updateComment(SuggestionComment $comment, User $editor, string $body, array $files, array $removeAttachmentIds): SuggestionComment
    {
        $before = RichText::mentionedUserIds($comment->body);
        $stored = [];

        try {
            DB::transaction(function () use ($comment, $editor, $body, $files, &$stored): void {
                $comment->update(['body' => $body, 'edited_at' => now()]);

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $comment, null, $editor);
                }
            });
        } catch (Throwable $exception) {
            $this->cleanup($stored);

            throw $exception;
        }

        $this->removeAttachments($comment, $removeAttachmentIds);
        $post = $comment->post()->firstOrFail();
        $this->notifyMentions($post, $editor, array_values(array_diff(RichText::mentionedUserIds($body), $before)), SuggestionMentioned::IN_COMMENT, $body);

        return $comment;
    }

    /** Borra el comentario y sus respuestas (en cascada), con sus adjuntos. */
    public function deleteComment(SuggestionComment $comment): void
    {
        $post = $comment->post()->firstOrFail();
        $ids = $this->descendants($comment);
        $attachments = Attachment::query()
            ->where('attachable_type', $comment->getMorphClass())
            ->whereIn('attachable_id', $ids)
            ->get();

        DB::transaction(function () use ($comment, $post, $attachments): void {
            foreach ($attachments as $attachment) {
                $this->storage->delete($attachment);
            }

            $comment->delete();
            $this->recount($post);
        });
    }

    /** Pone, cambia o quita la reacción de $user (una por persona). Devuelve la que queda. */
    public function react(SuggestionComment $comment, User $user, SuggestionReaction $reaction): ?SuggestionReaction
    {
        return DB::transaction(function () use ($comment, $user, $reaction): ?SuggestionReaction {
            $current = SuggestionCommentReaction::query()
                ->where('suggestion_comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->reaction === $reaction) {
                $current->delete();

                return null;
            }

            if ($current !== null) {
                $current->update(['reaction' => $reaction]);

                return $reaction;
            }

            try {
                // En un punto de guardado, para no abortar la transacción en PostgreSQL (como toggleVote).
                DB::transaction(fn () => SuggestionCommentReaction::query()->create([
                    'suggestion_comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'reaction' => $reaction,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Dos clics a la vez.
            }

            return $reaction;
        });
    }

    /**
     * Cambia el estado con su nota oficial (F-167). Sin estado nuevo ni nota, no hace nada.
     * Devuelve el evento del historial, o null.
     */
    public function changeStatus(SuggestionPost $post, User $user, SuggestionStatus $status, ?string $note): ?SuggestionStatusEvent
    {
        $note = $note === null ? null : (trim($note) === '' ? null : trim($note));

        if ($post->status === $status && $note === null) {
            return null;
        }

        $event = DB::transaction(function () use ($post, $user, $status, $note): SuggestionStatusEvent {
            $from = $post->status;

            if ($from !== $status) {
                $post->position = $this->endOfColumn($status);
            }

            $post->status = $status;
            $post->last_activity_at = now();
            $post->save();

            return SuggestionStatusEvent::query()->create([
                'suggestion_post_id' => $post->id,
                'from_status' => $from,
                'to_status' => $status,
                'note' => $note,
                'changed_by' => $user->id,
            ]);
        });

        $this->notifyStatus($post, $user, $status, $note);

        return $event;
    }

    /**
     * Mueve la sugerencia en el roadmap (F-168): a la columna de $status, antes de $beforeId o
     * después de $afterId (sin ninguno, al final). Cambiar de columna es cambiar de estado.
     */
    public function move(SuggestionPost $post, User $user, SuggestionStatus $status, ?int $beforeId, ?int $afterId): void
    {
        $changed = $post->status !== $status;

        DB::transaction(function () use ($post, $user, $status, $beforeId, $afterId, $changed): void {
            $ids = SuggestionPost::query()
                ->where('status', $status->value)
                ->whereKeyNot($post->id)
                ->orderBy('position')->orderByDesc('last_activity_at')->orderByDesc('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $index = count($ids);

            if ($beforeId !== null && ($at = array_search($beforeId, $ids, true)) !== false) {
                $index = (int) $at;
            } elseif ($afterId !== null && ($at = array_search($afterId, $ids, true)) !== false) {
                $index = (int) $at + 1;
            }

            array_splice($ids, $index, 0, [$post->id]);

            foreach ($ids as $position => $id) {
                if ($id !== $post->id) {
                    SuggestionPost::query()->whereKey($id)->where('position', '!=', $position + 1)->update(['position' => $position + 1]);
                }
            }

            $from = $post->status;
            $post->position = $index + 1;

            if ($changed) {
                $post->status = $status;
                $post->last_activity_at = now();
            }

            $post->save();

            if ($changed) {
                SuggestionStatusEvent::query()->create([
                    'suggestion_post_id' => $post->id,
                    'from_status' => $from,
                    'to_status' => $status,
                    'note' => null,
                    'changed_by' => $user->id,
                ]);
            }
        });

        if ($changed) {
            $this->notifyStatus($post, $user, $status, null);
        }
    }

    /**
     * @return array{0: SuggestionBoard, 1: SuggestionCategory|null}
     */
    private function taxonomy(int $boardId, ?int $categoryId): array
    {
        $board = SuggestionBoard::query()->where('is_active', true)->find($boardId);

        if ($board === null) {
            throw ValidationException::withMessages(['suggestion_board_id' => __('help.suggestions.board_inactive')]);
        }

        if ($categoryId === null) {
            return [$board, null];
        }

        $category = SuggestionCategory::query()->where('is_active', true)->find($categoryId);

        if ($category === null) {
            throw ValidationException::withMessages(['suggestion_category_id' => __('help.suggestions.category_inactive')]);
        }

        if ($category->suggestion_board_id !== $board->id) {
            throw ValidationException::withMessages(['suggestion_category_id' => __('help.suggestions.category_board')]);
        }

        return [$board, $category];
    }

    private function uniqueSlug(int $boardId, string $title, ?int $ignore = null): string
    {
        $base = Str::limit(Str::slug($title), 150, '') ?: 'sugerencia';
        $slug = $base;
        $suffix = 2;

        while (SuggestionPost::query()->where('suggestion_board_id', $boardId)->where('slug', $slug)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function recount(SuggestionPost $post, bool $touch = false): void
    {
        $attributes = [
            'vote_count' => SuggestionVote::query()->where('suggestion_post_id', $post->id)->count(),
            'comment_count' => SuggestionComment::query()->where('suggestion_post_id', $post->id)->count(),
        ];

        if ($touch) {
            $attributes['last_activity_at'] = now();
        }

        // Sin tocar updated_at ni el registro de auditoría: son contadores.
        SuggestionPost::query()->whereKey($post->id)->update($attributes);
        $post->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
    }

    private function endOfColumn(SuggestionStatus $status): int
    {
        return (int) SuggestionPost::query()->where('status', $status->value)->max('position') + 1;
    }

    /**
     * @param  list<int>  $ids
     */
    private function removeAttachments(SuggestionPost|SuggestionComment $owner, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Attachment::query()->whereMorphedTo('attachable', $owner)->whereKey($ids)->get()
            ->each(fn (Attachment $attachment) => $this->storage->delete($attachment));
    }

    /**
     * @param  list<Attachment>  $stored
     */
    private function cleanup(array $stored): void
    {
        foreach ($stored as $attachment) {
            rescue(fn () => $this->storage->delete($attachment), report: false);
        }
    }

    /**
     * El comentario y todas sus respuestas.
     *
     * @return list<int>
     */
    private function descendants(SuggestionComment $comment): array
    {
        $all = SuggestionComment::query()->where('suggestion_post_id', $comment->suggestion_post_id)->get(['id', 'parent_id']);
        $ids = [$comment->id];
        $frontier = [$comment->id];

        while ($frontier !== []) {
            $frontier = $all->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return array_values($ids);
    }

    /**
     * Avisa a los mencionados que pueden ver las sugerencias (no a quien escribe). Devuelve a quién.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function notifyMentions(SuggestionPost $post, User $actor, array $ids, string $context, string $html): array
    {
        $users = $this->recipients(array_values(array_diff($ids, [$actor->id])));

        if ($users !== []) {
            Notification::send($users, new SuggestionMentioned($post->id, $post->title, $actor->name, $context, RichText::toPlainText($html, 160)));
        }

        return array_map(fn (User $user): int => $user->id, $users);
    }

    private function notifyStatus(SuggestionPost $post, User $actor, SuggestionStatus $status, ?string $note): void
    {
        if ($post->author_id === $actor->id) {
            return;
        }

        $author = $this->recipients([$post->author_id]);

        if ($author !== []) {
            Notification::send($author, new SuggestionStatusChanged($post->id, $post->title, $actor->name, $status->label(), $note));
        }
    }

    /**
     * Personas activas que usan la ayuda (nunca un colaborador externo ni un cliente).
     *
     * @param  list<int>  $ids
     * @return list<User>
     */
    private function recipients(array $ids): array
    {
        // Modo de prueba (D-239): con la ayuda o las sugerencias apagadas no se avisa a nadie.
        if ($ids === [] || ! AppModules::enabled(AppModule::Help) || ! AppModules::enabled(AppModule::Suggestions)) {
            return [];
        }

        return array_values(User::query()->whereKey($ids)->active()->role(User::WEEKLY_ROLES)->get()->all());
    }
}
