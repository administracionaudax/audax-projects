<?php

namespace App\Models;

use App\Enums\SalesDocumentType;
use App\Enums\SeriesKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Serie de numeración (PLAN-EMISION §4.1 y §7.1; P-2, D-419). El formato lleva el año ([YY] o
 * [YYYY]) y tantas # como cifras del número: «F[YY]####» da F270001. El contador es por serie y año
 * (NumberingCounter), así la serie empieza de nuevo cada año. Una serie de facturas tiene su serie de
 * rectificativas (L-02). `starts_on`: desde qué día se puede emitir con ella.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property SalesDocumentType $document_type
 * @property string $format
 * @property int|null $refund_series_id
 * @property SeriesKind $kind
 * @property int|null $sif_installation_id
 * @property CarbonImmutable|null $starts_on
 * @property bool $is_default
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read NumberingSeries|null $refundSeries
 * @property-read SifInstallation|null $installation
 */
#[Fillable(['code', 'name', 'document_type', 'format', 'refund_series_id', 'kind', 'sif_installation_id', 'starts_on', 'is_default', 'archived_at'])]
class NumberingSeries extends Model
{
    protected $table = 'numbering_series';

    /** Formato válido: prefijo (letras, cifras, «-» o «/»), el año opcional y de 3 a 8 #. */
    public const string FORMAT_PATTERN = '/^[A-Z0-9\/-]{0,8}(\[YY\]|\[YYYY\])?[A-Z0-9\/-]{0,4}#{3,8}$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => SalesDocumentType::class,
            'kind' => SeriesKind::class,
            'starts_on' => 'immutable_date',
            'is_default' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<NumberingSeries, $this>
     */
    public function refundSeries(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_series_id');
    }

    /**
     * @return BelongsTo<SifInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(SifInstallation::class, 'sif_installation_id');
    }

    /**
     * @return HasMany<NumberingCounter, $this>
     */
    public function counters(): HasMany
    {
        return $this->hasMany(NumberingCounter::class, 'series_id');
    }

    /**
     * @param  Builder<NumberingSeries>  $query
     * @return Builder<NumberingSeries>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isTest(): bool
    {
        return $this->kind === SeriesKind::Test;
    }

    /** El número completo: «F[YY]####», 2027 y 1 → «F270001». */
    public function formatNumber(int $year, int $number): string
    {
        return self::render($this->format, $year, $number);
    }

    public static function render(string $format, int $year, int $number): string
    {
        $withYear = str_replace(['[YYYY]', '[YY]'], [sprintf('%04d', $year), sprintf('%02d', $year % 100)], $format);

        return (string) preg_replace_callback('/#+/', fn (array $hashes): string => str_pad((string) $number, strlen($hashes[0]), '0', STR_PAD_LEFT), $withYear);
    }
}
