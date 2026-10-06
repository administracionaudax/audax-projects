<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\DayPlanComment;
use App\Models\User;

/**
 * Los comentarios del plan del día (D-256): los que te han dejado en tus líneas y los que has
 * escrito tú (en las tuyas o en las de tu equipo).
 */
final class DayPlanCommentsSection extends Section
{
    public function key(): string
    {
        return 'comentarios-plan-del-dia';
    }

    protected function textKey(): string
    {
        return 'day_plan_comments';
    }

    protected function columnKeys(): array
    {
        return ['id', 'date', 'line', 'line_owner', 'author', 'body', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $comments = DayPlanComment::query()
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->orWhereHas('item', fn ($item) => $item->withTrashed()->where('user_id', $user->id)))
            ->with(['item' => fn ($item) => $item->withTrashed()->with('user:id,name'), 'user:id,name'])
            ->orderBy('id')
            ->get();

        foreach ($comments as $comment) {
            yield [
                'id' => $comment->id,
                'date' => self::date($comment->item->date),
                'line' => $comment->item->text,
                'line_owner' => $comment->item->user->name,
                'author' => $comment->user->name,
                'body' => $comment->body,
                'created_at' => self::instant($comment->created_at),
            ];
        }
    }
}
