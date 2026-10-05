<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HelpReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Novedad automática del centro de ayuda (F-150): una versión por semana, «V.serie.mes.semana»,
 * con su lista de cambios. Se puede ocultar.
 *
 * @property int $id
 * @property int $major_version
 * @property int $month_number
 * @property int $week_of_month
 * @property string $summary
 * @property bool $is_hidden
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, HelpReleaseChange> $changes
 * @property-read Collection<int, HelpUpdateLike> $likes
 * @property-read Collection<int, HelpTutorial> $tutorials
 */
#[Fillable(['major_version', 'month_number', 'week_of_month', 'summary', 'is_hidden'])]
class HelpRelease extends Model
{
    /** @use HasFactory<HelpReleaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'major_version' => 'integer',
            'month_number' => 'integer',
            'week_of_month' => 'integer',
            'is_hidden' => 'boolean',
        ];
    }

    /** «V1.10.2» (serie, mes y semana del mes). */
    public function versionLabel(): string
    {
        return "V{$this->major_version}.{$this->month_number}.{$this->week_of_month}";
    }

    /**
     * @return HasMany<HelpReleaseChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(HelpReleaseChange::class)->orderBy('position');
    }

    /**
     * @return MorphMany<HelpUpdateLike, $this>
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(HelpUpdateLike::class, 'likeable');
    }

    /**
     * @return HasMany<HelpTutorial, $this>
     */
    public function tutorials(): HasMany
    {
        return $this->hasMany(HelpTutorial::class);
    }
}
