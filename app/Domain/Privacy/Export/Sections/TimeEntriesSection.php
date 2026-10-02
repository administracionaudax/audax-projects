<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Duration;

/**
 * Horas: todas las entradas propias, con su proyecto, cliente, bolsa y tarea. Sin las tarifas ni los
 * costes congelados (datos económicos de la empresa, SPEC §5). Por bloques, sin cargarlas todas.
 */
final class TimeEntriesSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'horas';
    }

    protected function textKey(): string
    {
        return 'time_entries';
    }

    protected function columnKeys(): array
    {
        return [
            'id', 'date', 'client', 'project', 'hour_bank', 'task', 'minutes', 'duration', 'is_billable',
            'status', 'description', 'started_at', 'ended_at', 'approved_at', 'created_at', 'updated_at',
        ];
    }

    public function rows(User $user): iterable
    {
        $entries = TimeEntry::query()
            ->where('user_id', $user->id)
            ->with([
                'project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name', 'client_id']),
                'project.client' => fn ($client) => $client->withTrashed()->select(['id', 'name']),
                'hourBank' => fn ($bank) => $bank->withTrashed()->select(['id', 'name']),
                'task' => fn ($task) => $task->withTrashed()->select(['id', 'title']),
            ])
            ->lazyById(self::CHUNK);

        foreach ($entries as $entry) {
            yield [
                'id' => $entry->id,
                'date' => self::date($entry->date),
                'client' => $entry->project->client?->name,
                'project' => "{$entry->project->code} · {$entry->project->name}",
                'hour_bank' => $entry->hourBank?->name,
                'task' => $entry->task->title,
                'minutes' => $entry->minutes,
                'duration' => Duration::format($entry->minutes),
                'is_billable' => $entry->is_billable,
                'status' => $entry->status->label(),
                'description' => $entry->description,
                'started_at' => self::instant($entry->started_at),
                'ended_at' => self::instant($entry->ended_at),
                'approved_at' => self::instant($entry->approved_at),
                'created_at' => self::instant($entry->created_at),
                'updated_at' => self::instant($entry->updated_at),
            ];
        }
    }
}
