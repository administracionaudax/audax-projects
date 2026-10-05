<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SuggestionPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Sugerencia (F-161 a F-168). body en el formato de App\Support\RichText; adjuntos con Attachment.
 * vote_count, comment_count y last_activity_at se mantienen al votar y comentar (orden Top y
 * Trending, F-162). position ordena la columna del roadmap (F-168).
 *
 * @property int $id
 * @property int $suggestion_board_id
 * @property int|null $suggestion_category_id
 * @property int $author_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property SuggestionStatus $status
 * @property int $position
 * @property int $vote_count
 * @property int $comment_count
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SuggestionBoard $board
 * @property-read SuggestionCategory|null $category
 * @property-read User $author
 * @property-read Collection<int, SuggestionVote> $votes
 * @property-read Collection<int, SuggestionComment> $comments
 * @property-read Collection<int, SuggestionStatusEvent> $statusEvents
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable(['suggestion_board_id', 'suggestion_category_id', 'author_id', 'title', 'slug', 'body', 'status', 'position', 'vote_count', 'comment_count', 'last_activity_at'])]
class SuggestionPost extends Model
{
    /** @use HasFactory<SuggestionPostFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'position' => 0,
        'vote_count' => 0,
        'comment_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'position' => 'integer',
            'vote_count' => 'integer',
            'comment_count' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SuggestionBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(SuggestionBoard::class, 'suggestion_board_id');
    }

    /**
     * @return BelongsTo<SuggestionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SuggestionCategory::class, 'suggestion_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<SuggestionVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(SuggestionVote::class);
    }

    /**
     * @return HasMany<SuggestionComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(SuggestionComment::class);
    }

    /**
     * @return HasMany<SuggestionStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(SuggestionStatusEvent::class)->orderBy('created_at');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
