<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\Client;
use App\Models\TaskArchive;
use App\Models\TaskSuggestionBatch;
use App\Models\User;

/**
 * Lo personal de las tareas de «Mi espacio» (Fase 10, 10.6, D-204): las tareas que la IA te ha
 * propuesto y aún no has creado ni descartado, y las tareas que has archivado de tu lista. Las tareas
 * en sí son del proyecto y no entran.
 */
final class MySpaceTasksSection extends Section
{
    public function key(): string
    {
        return 'mi-espacio-tareas';
    }

    protected function textKey(): string
    {
        return 'my_space_tasks';
    }

    protected function columnKeys(): array
    {
        return ['kind', 'title', 'client', 'week', 'author', 'date'];
    }

    public function rows(User $user): iterable
    {
        $batch = TaskSuggestionBatch::query()->with('cycle:id,number')->where('user_id', $user->id)->first();
        $clients = Client::query()->withTrashed()->whereKey(array_filter(array_column($batch->items ?? [], 'client_id')))->pluck('name', 'id');

        foreach ($batch->items ?? [] as $item) {
            yield [
                'kind' => self::text('privacy.export.weeklies.my_space_kind.suggestion'),
                'title' => $item['title'] ?? null,
                'client' => isset($item['client_id']) ? ($clients[$item['client_id']] ?? $item['client_name'] ?? null) : null,
                'week' => $batch->cycle?->number,
                'author' => $item['author_name'] ?? null,
                'date' => self::instant($batch->generated_at),
            ];
        }

        $archives = TaskArchive::query()->where('user_id', $user->id)->with('task:id,title')->orderBy('id')->get();

        foreach ($archives as $archive) {
            yield [
                'kind' => self::text('privacy.export.weeklies.my_space_kind.archived'),
                'title' => $archive->task->title,
                'client' => null,
                'week' => null,
                'author' => null,
                'date' => self::instant($archive->archived_at),
            ];
        }
    }
}
