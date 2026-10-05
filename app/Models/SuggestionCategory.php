<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\SuggestionCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Categoría de un tablero de sugerencias (F-160). La de slug «bugs» la abre «Reportar bug» (F-149,
 * F-169).
 *
 * @property int $id
 * @property int $suggestion_board_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SuggestionBoard $board
 */
#[Fillable(['suggestion_board_id', 'name', 'slug', 'description', 'position', 'is_active', 'created_by'])]
class SuggestionCategory extends Model
{
    /** @use HasFactory<SuggestionCategoryFactory> */
    use HasFactory, LogsDomainActivity;

    public const string BUGS_SLUG = 'bugs';

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
     * @return BelongsTo<SuggestionBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(SuggestionBoard::class, 'suggestion_board_id');
    }
}
