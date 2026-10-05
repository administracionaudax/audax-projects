<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SuggestionBoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tablero de sugerencias (F-160): se crea, edita, oculta (is_active) y reordena.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, SuggestionCategory> $categories
 * @property-read Collection<int, SuggestionPost> $posts
 */
#[Fillable(['name', 'slug', 'description', 'position', 'is_active', 'created_by'])]
class SuggestionBoard extends Model
{
    /** @use HasFactory<SuggestionBoardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SuggestionCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(SuggestionCategory::class)->orderBy('position');
    }

    /**
     * @return HasMany<SuggestionPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(SuggestionPost::class);
    }
}
