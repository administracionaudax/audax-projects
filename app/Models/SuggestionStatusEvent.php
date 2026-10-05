<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cambio de estado de una sugerencia con su nota oficial (F-167). Lo escribe quien gestiona.
 *
 * @property int $id
 * @property int $suggestion_post_id
 * @property SuggestionStatus|null $from_status
 * @property SuggestionStatus $to_status
 * @property string|null $note
 * @property int|null $changed_by
 * @property CarbonImmutable|null $created_at
 * @property-read SuggestionPost $post
 * @property-read User|null $changer
 */
#[Fillable(['suggestion_post_id', 'from_status', 'to_status', 'note', 'changed_by'])]
class SuggestionStatusEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => SuggestionStatus::class,
            'to_status' => SuggestionStatus::class,
        ];
    }

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
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
