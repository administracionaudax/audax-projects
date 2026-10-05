<?php

use App\Domain\Privacy\Export\Sections\HelpLikesSection;
use App\Domain\Privacy\Export\Sections\SuggestionCommentsSection;
use App\Domain\Privacy\Export\Sections\SuggestionsSection;
use App\Domain\Privacy\Export\Sections\SuggestionVotesSection;
use App\Enums\SuggestionReaction;
use App\Enums\SuggestionStatus;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpUpdateLike;
use App\Models\SuggestionBoard;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionVote;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| RGPD del centro de ayuda (10.7, D-211): la exportación de datos personales lleva las sugerencias
| de la persona, sus comentarios, sus votos y reacciones y sus «me gusta» a las novedades; nunca lo
| de otra persona. Nada de esto caduca (como las weeklies): es el histórico del producto.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->elena = userWithRole('employee', ['name' => 'Elena']);
    $this->other = userWithRole('employee', ['name' => 'Otra']);
    $this->board = SuggestionBoard::query()->firstOrFail();
    $this->rows = fn (object $section, User $user): array => iterator_to_array($section->rows($user), false);
});

it('las secciones de la ayuda están en la exportación y tienen sus textos', function () {
    expect(config('privacy.export_sections'))->toContain(SuggestionsSection::class, SuggestionCommentsSection::class, SuggestionVotesSection::class, HelpLikesSection::class);

    foreach ([new SuggestionsSection, new SuggestionCommentsSection, new SuggestionVotesSection, new HelpLikesSection] as $section) {
        expect($section->description())->not->toStartWith('privacy.')
            ->and(array_filter($section->columns(), fn (string $label): bool => str_starts_with($label, 'privacy.')))->toBe([]);
    }
});

it('mis sugerencias, comentarios, votos, reacciones y «me gusta»; nada de otra persona', function () {
    $mine = SuggestionPost::factory()->create(['suggestion_board_id' => $this->board->id, 'author_id' => $this->elena->id, 'title' => 'Modo oscuro', 'body' => '<p>Por <strong>favor</strong></p>', 'status' => SuggestionStatus::Planned]);
    $theirs = SuggestionPost::factory()->create(['suggestion_board_id' => $this->board->id, 'author_id' => $this->other->id, 'title' => 'De otra']);
    $comment = SuggestionComment::query()->create(['suggestion_post_id' => $theirs->id, 'author_id' => $this->elena->id, 'body' => '<p>Me apunto</p>']);
    SuggestionComment::query()->create(['suggestion_post_id' => $mine->id, 'author_id' => $this->other->id, 'body' => '<p>Ajeno</p>']);
    SuggestionVote::query()->create(['suggestion_post_id' => $theirs->id, 'user_id' => $this->elena->id]);
    SuggestionVote::query()->create(['suggestion_post_id' => $mine->id, 'user_id' => $this->other->id]);
    $foreign = SuggestionComment::query()->create(['suggestion_post_id' => $theirs->id, 'author_id' => $this->other->id, 'body' => '<p>x</p>']);
    SuggestionCommentReaction::query()->create(['suggestion_comment_id' => $foreign->id, 'user_id' => $this->elena->id, 'reaction' => SuggestionReaction::Rocket]);
    $release = HelpRelease::query()->create(['major_version' => 1, 'month_number' => 9, 'week_of_month' => 4, 'summary' => 'x']);
    $update = HelpManualUpdate::query()->create(['published_on' => '2026-10-01', 'title' => 'Nuevo panel', 'subtitle' => 's', 'body' => '']);
    foreach ([$release, $update] as $target) {
        HelpUpdateLike::query()->create(['likeable_type' => $target->getMorphClass(), 'likeable_id' => $target->id, 'user_id' => $this->elena->id]);
    }
    HelpUpdateLike::query()->create(['likeable_type' => $update->getMorphClass(), 'likeable_id' => $update->id, 'user_id' => $this->other->id]);

    $posts = ($this->rows)(new SuggestionsSection, $this->elena);
    $comments = ($this->rows)(new SuggestionCommentsSection, $this->elena);
    $votes = ($this->rows)(new SuggestionVotesSection, $this->elena);
    $likes = ($this->rows)(new HelpLikesSection, $this->elena);

    expect($posts)->toHaveCount(1)
        ->and($posts[0])->toMatchArray(['title' => 'Modo oscuro', 'body' => 'Por favor', 'status' => 'Planificada', 'board' => $this->board->name])
        ->and($comments)->toHaveCount(1)
        ->and($comments[0])->toMatchArray(['id' => $comment->id, 'post' => 'De otra', 'body' => 'Me apunto'])
        ->and($votes)->toHaveCount(2)
        ->and($votes[0])->toMatchArray(['kind' => 'Voto a una sugerencia', 'post' => 'De otra', 'reaction' => null])
        ->and($votes[1])->toMatchArray(['kind' => 'Reacción a un comentario', 'reaction' => 'Impulso'])
        ->and(array_column($likes, 'update'))->toBe(['Notas de lanzamiento V.1.9.4', 'Nuevo panel']);
});
