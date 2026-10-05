<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voto de una persona a una sugerencia (F-163): uno por persona.
 *
 * @property int $id
 * @property int $suggestion_post_id
 * @property int $user_id
 * @property CarbonImmutable|null $created_at
 * @property-read SuggestionPost $post
 * @property-read User $user
 */
#[Fillable(['suggestion_post_id', 'user_id'])]
class SuggestionVote extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<SuggestionPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SuggestionPost::class, 'suggestion_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
