<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionVote;
use App\Models\User;

/**
 * Tus votos a las sugerencias y tus reacciones a sus comentarios (10.7, D-211).
 */
final class SuggestionVotesSection extends Section
{
    public function key(): string
    {
        return 'sugerencias-votos';
    }

    protected function textKey(): string
    {
        return 'suggestion_votes';
    }

    protected function columnKeys(): array
    {
        return ['kind', 'post', 'reaction', 'date'];
    }

    public function rows(User $user): iterable
    {
        $votes = SuggestionVote::query()->where('user_id', $user->id)->with('post:id,title')->orderBy('id')->get();

        foreach ($votes as $vote) {
            yield [
                'kind' => self::text('privacy.export.help.vote'),
                'post' => $vote->post->title,
                'reaction' => null,
                'date' => self::instant($vote->created_at),
            ];
        }

        $reactions = SuggestionCommentReaction::query()->where('user_id', $user->id)->with('comment.post:id,title')->orderBy('id')->get();

        foreach ($reactions as $reaction) {
            yield [
                'kind' => self::text('privacy.export.help.reaction'),
                'post' => $reaction->comment->post->title,
                'reaction' => $reaction->reaction->label(),
                'date' => self::instant($reaction->created_at),
            ];
        }
    }
}
