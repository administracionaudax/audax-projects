<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Tutorial en vídeo del centro de ayuda (F-155). El vídeo es un Attachment (attachable = este
 * tutorial) en disco privado; hasta 200 MB.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property int|null $help_release_id
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HelpRelease|null $release
 * @property-read Attachment|null $video
 */
#[Fillable(['title', 'description', 'help_release_id', 'position'])]
class HelpTutorial extends Model
{
    use LogsDomainActivity;

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

    /**
     * @return MorphOne<Attachment, $this>
     */
    public function video(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->latestOfMany();
    }
}
