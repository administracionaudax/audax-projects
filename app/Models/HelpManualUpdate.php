<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Actualización puntual escrita a mano en el centro de ayuda (F-151). body en el formato de
 * App\Support\RichText.
 *
 * @property int $id
 * @property CarbonImmutable $published_on
 * @property string $title
 * @property string $subtitle
 * @property string $body
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, HelpUpdateLike> $likes
 */
#[Fillable(['published_on', 'title', 'subtitle', 'body', 'created_by'])]
class HelpManualUpdate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphMany<HelpUpdateLike, $this>
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(HelpUpdateLike::class, 'likeable');
    }
}
