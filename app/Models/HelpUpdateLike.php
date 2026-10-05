<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * «Me gusta» de una persona en una novedad (HelpRelease) o actualización (HelpManualUpdate), F-153.
 *
 * @property int $id
 * @property string $likeable_type
 * @property int $likeable_id
 * @property int $user_id
 * @property CarbonImmutable|null $created_at
 * @property-read HelpRelease|HelpManualUpdate|null $likeable
 * @property-read User $user
 */
#[Fillable(['likeable_type', 'likeable_id', 'user_id'])]
class HelpUpdateLike extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return MorphTo<Model, $this>
     */
    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
