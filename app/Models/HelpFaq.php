<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pregunta frecuente de una sección (F-156). answer en el formato de App\Support\RichText.
 *
 * @property int $id
 * @property int $help_faq_section_id
 * @property string $question
 * @property string $answer
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HelpFaqSection $section
 */
#[Fillable(['help_faq_section_id', 'question', 'answer', 'position'])]
class HelpFaq extends Model
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
     * @return BelongsTo<HelpFaqSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(HelpFaqSection::class, 'help_faq_section_id');
    }
}
