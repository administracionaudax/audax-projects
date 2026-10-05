<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use App\Models\WeeklySubmission;

/**
 * Tus weeklies (Fase 10, 10.5): una fila por semana con su borrador o envío (primer envío, último
 * reenvío y último autoguardado) y cuántos apuntes tiene. Los apuntes van en WeeklyEntriesSection.
 */
final class WeeklySubmissionsSection extends Section
{
    public function key(): string
    {
        return 'weeklies-envios';
    }

    protected function textKey(): string
    {
        return 'weekly_submissions';
    }

    protected function columnKeys(): array
    {
        return ['id', 'week', 'week_label', 'deadline_date', 'submitted_at', 'resubmitted_at', 'draft_saved_at', 'entries', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $submissions = WeeklySubmission::query()
            ->where('user_id', $user->id)
            ->with('cycle:id,number,label,deadline_date')
            ->withCount('entries')
            ->orderBy('id')
            ->get();

        foreach ($submissions as $submission) {
            yield [
                'id' => $submission->id,
                'week' => $submission->cycle->number,
                'week_label' => $submission->cycle->label,
                'deadline_date' => self::date($submission->cycle->deadline_date),
                'submitted_at' => self::instant($submission->submitted_at),
                'resubmitted_at' => self::instant($submission->resubmitted_at),
                'draft_saved_at' => self::instant($submission->draft_saved_at),
                'entries' => (int) $submission->getAttribute('entries_count'),
                'created_at' => self::instant($submission->created_at),
            ];
        }
    }
}
