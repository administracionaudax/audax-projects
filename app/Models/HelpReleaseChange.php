<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de una novedad automática (F-150), reordenable.
 *
 * @property int $id
 * @property int $help_release_id
 * @property string $description
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HelpRelease $release
 */
#[Fillable(['help_release_id', 'description', 'position'])]
class HelpReleaseChange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HelpRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(HelpRelease::class, 'help_release_id');
    }
}
