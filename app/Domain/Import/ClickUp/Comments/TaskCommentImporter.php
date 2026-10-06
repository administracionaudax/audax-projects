<?php

namespace App\Domain\Import\ClickUp\Comments;

use App\Domain\Import\ClickUp\ClickUpImporter;
use App\Domain\Import\ClickUp\ImportRefs;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Comentarios de las tareas de ClickUp (descargados con `scripts/clickup`, un JSON {id de tarea:
 * [comentarios de la API v2]}). Van después de `app:import-clickup`: la tarea se encuentra por
 * import_refs y el comentario queda registrado con el tipo `task_comment`, así que repetir no duplica.
 *
 * - Autor: la persona interna con el mismo email; los de ClickBot (avisos automáticos de «fecha
 *   límite») y los de personas que no existen no se importan.
 * - Texto: párrafos, negrita, tachado, enlaces, menciones (como las de la app) y las imágenes y los
 *   marcadores, como enlace. Saneado con RichText, como lo que se escribe en la app.
 * - Fechas: las de ClickUp. Sin avisos a nadie (no pasa por TaskNotifier).
 */
final class TaskCommentImporter
{
    public const string BOT_EMAIL = 'clickbot@clickup.com';

    /** @var array{created: int, unchanged: int, bot: int, no_task: int, no_author: int, empty: int} */
    private array $counts = ['created' => 0, 'unchanged' => 0, 'bot' => 0, 'no_task' => 0, 'no_author' => 0, 'empty' => 0];

    /** @var array<string, int> */
    private array $usersByEmail = [];

    /**
     * @param  array<array-key, mixed>  $dump
     * @return array{created: int, unchanged: int, bot: int, no_task: int, no_author: int, empty: int}
     */
    public function import(array $dump, bool $dryRun = false): array
    {
        $refs = new ImportRefs(ClickUpImporter::SOURCE);
        $refs->load();
        $this->usersByEmail = User::query()->whereNull('client_id')->pluck('id', 'email')
            ->mapWithKeys(fn (int $id, string $email): array => [mb_strtolower($email) => $id])->all();

        $run = function () use ($dump, $refs, $dryRun): void {
            foreach ($dump as $clickUpTaskId => $comments) {
                if (! is_array($comments)) {
                    continue;
                }

                foreach ($comments as $comment) {
                    if (is_array($comment)) {
                        $this->importOne((string) $clickUpTaskId, $comment, $refs, $dryRun);
                    }
                }
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        return $this->counts;
    }

    /**
     * @param  array<array-key, mixed>  $comment
     */
    private function importOne(string $clickUpTaskId, array $comment, ImportRefs $refs, bool $dryRun): void
    {
        $externalId = (string) ($comment['id'] ?? '');
        $email = mb_strtolower((string) (is_array($comment['user'] ?? null) ? ($comment['user']['email'] ?? '') : ''));

        if ($email === self::BOT_EMAIL) {
            $this->counts['bot']++;

            return;
        }

        if ($externalId === '' || $refs->find('task_comment', $externalId) !== null) {
            $this->counts['unchanged']++;

            return;
        }

        $taskId = $refs->find('task', $clickUpTaskId);

        if ($taskId === null || ! Task::query()->whereKey($taskId)->exists()) {
            $this->counts['no_task']++;

            return;
        }

        $authorId = $this->usersByEmail[$email] ?? null;

        if ($authorId === null) {
            $this->counts['no_author']++;

            return;
        }

        $body = (string) RichText::sanitize(self::toHtml(is_array($comment['comment'] ?? null) ? $comment['comment'] : [], $this->usersByEmail));

        if (RichText::isBlank($body)) {
            $this->counts['empty']++;

            return;
        }

        $this->counts['created']++;

        if ($dryRun) {
            return;
        }

        $at = is_numeric($comment['date'] ?? null)
            ? CarbonImmutable::createFromTimestampMs((int) $comment['date'])
            : now();

        $model = new TaskComment([
            'task_id' => $taskId,
            'user_id' => $authorId,
            'body' => $body,
            'mentioned_user_ids' => RichText::mentionedUserIds($body) ?: null,
        ]);
        $model->created_at = $at;
        $model->updated_at = $at;
        $model->save();

        $refs->put('task_comment', $externalId, 'task_comment', $model->id);
    }

    /**
     * Las piezas del comentario de ClickUp (Quill: texto con atributos, menciones, imágenes…) en el
     * HTML que admite la app. Un salto de línea cierra el párrafo.
     *
     * @param  array<array-key, mixed>  $parts
     * @param  array<string, int>  $usersByEmail
     */
    public static function toHtml(array $parts, array $usersByEmail = []): string
    {
        $paragraphs = [];
        $current = '';

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $type = (string) ($part['type'] ?? 'text');
            $attributes = is_array($part['attributes'] ?? null) ? $part['attributes'] : [];

            $html = match ($type) {
                'tag' => self::mention($part, $usersByEmail),
                'image' => self::link(
                    (string) (is_array($part['image'] ?? null) ? ($part['image']['url'] ?? $part['image']['thumbnail_large'] ?? '') : ''),
                    (string) ($part['text'] ?? 'imagen'),
                ),
                'bookmark' => self::link((string) (is_array($part['bookmark'] ?? null) ? ($part['bookmark']['url'] ?? '') : ''), null),
                'link_mention' => self::link((string) (is_array($part['link_mention'] ?? null) ? ($part['link_mention']['url'] ?? '') : ''), null),
                default => null,
            };

            if ($html !== null) {
                $current .= $html;

                continue;
            }

            $lines = explode("\n", (string) ($part['text'] ?? ''));

            foreach ($lines as $index => $line) {
                if ($index > 0) {
                    $paragraphs[] = $current;
                    $current = '';
                }

                if ($line === '') {
                    continue;
                }

                $text = e($line);

                if (is_string($attributes['link'] ?? null)) {
                    $text = self::link($attributes['link'], $line) ?? $text;
                }
                if (($attributes['bold'] ?? false) === true) {
                    $text = "<strong>{$text}</strong>";
                }
                if (($attributes['italic'] ?? false) === true) {
                    $text = "<em>{$text}</em>";
                }
                if (($attributes['strike'] ?? false) === true) {
                    $text = "<s>{$text}</s>";
                }

                $current .= $text;
            }
        }

        $paragraphs[] = $current;

        return implode('', array_map(
            fn (string $paragraph): string => "<p>{$paragraph}</p>",
            array_values(array_filter($paragraphs, fn (string $paragraph): bool => trim(strip_tags($paragraph, '<span>')) !== '')),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $part
     * @param  array<string, int>  $usersByEmail
     */
    private static function mention(array $part, array $usersByEmail): string
    {
        $user = is_array($part['user'] ?? null) ? $part['user'] : [];
        $label = ltrim((string) ($part['text'] ?? ($user['username'] ?? '')), '@');
        $id = $usersByEmail[mb_strtolower((string) ($user['email'] ?? ''))] ?? null;

        return $id === null
            ? e('@'.$label)
            : '<span data-type="mention" data-id="'.$id.'" data-label="'.e($label).'">@'.e($label).'</span>';
    }

    private static function link(string $url, ?string $text): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return '<a href="'.e($url).'">'.e($text ?? $url).'</a>';
    }
}
