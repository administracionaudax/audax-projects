<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\HelpFaqSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sección de preguntas frecuentes (F-156), reordenable. No se borra con preguntas dentro.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, HelpFaq> $faqs
 */
#[Fillable(['name', 'position'])]
class HelpFaqSection extends Model
{
    /** @use HasFactory<HelpFaqSectionFactory> */
    use HasFactory, LogsDomainActivity;

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
     * @return HasMany<HelpFaq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(HelpFaq::class)->orderBy('position');
    }
}
