<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Domain\Weeklies\Help\HelpReleaseCalendar;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpUpdateLike;
use App\Models\User;

/**
 * Los «me gusta» que has dado a las novedades del centro de ayuda (10.7, D-211).
 */
final class HelpLikesSection extends Section
{
    public function key(): string
    {
        return 'ayuda-me-gusta';
    }

    protected function textKey(): string
    {
        return 'help_likes';
    }

    protected function columnKeys(): array
    {
        return ['update', 'date'];
    }

    public function rows(User $user): iterable
    {
        $likes = HelpUpdateLike::query()->where('user_id', $user->id)->with('likeable')->orderBy('id')->get();

        foreach ($likes as $like) {
            $target = $like->likeable;

            yield [
                'update' => match (true) {
                    $target instanceof HelpRelease => (string) __('help.releases.title', ['version' => HelpReleaseCalendar::label($target->major_version, $target->month_number, $target->week_of_month)]),
                    $target instanceof HelpManualUpdate => $target->title,
                    default => null,
                },
                'date' => self::instant($like->created_at),
            ];
        }
    }
}
