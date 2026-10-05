<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use App\Models\WeeklyEntry;

/**
 * Los apuntes de tus weeklies (Fase 10, 10.5): semana, cliente («General / Interno» sin cliente),
 * proyecto, texto y si salió del dictado. Solo los tuyos.
 */
final class WeeklyEntriesSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'weeklies-apuntes';
    }

    protected function textKey(): string
    {
        return 'weekly_entries';
    }

    protected function columnKeys(): array
    {
        return ['id', 'week', 'client', 'project', 'body', 'source', 'created_at', 'updated_at'];
    }

    public function rows(User $user): iterable
    {
        $entries = WeeklyEntry::query()
            ->whereHas('submission', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'submission' => fn ($query) => $query->select(['id', 'weekly_cycle_id'])->with('cycle:id,number'),
                'client:id,name',
                'project' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name']),
            ])
            ->lazyById(self::CHUNK);

        foreach ($entries as $entry) {
            yield [
                'id' => $entry->id,
                'week' => $entry->submission->cycle->number,
                'client' => $entry->client?->name ?? self::text('weeklies.report.general'),
                'project' => $entry->project !== null ? "{$entry->project->code} · {$entry->project->name}" : null,
                'body' => $entry->body,
                'source' => $entry->source->label(),
                'created_at' => self::instant($entry->created_at),
                'updated_at' => self::instant($entry->updated_at),
            ];
        }
    }
}
