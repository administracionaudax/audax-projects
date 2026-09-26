<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de un aviso de bolsa ya enviado (D-035). La clave única (hour_bank_id, key)
 * impide repetirlo: "threshold:75" una sola vez; "overage:2026-09-26" una vez al día.
 *
 * @property int $id
 * @property int $hour_bank_id
 * @property string $kind
 * @property int|null $threshold
 * @property CarbonImmutable $notified_on
 * @property string $key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HourBank $hourBank
 */
#[Fillable(['hour_bank_id', 'kind', 'threshold', 'notified_on', 'key'])]
class HourBankAlert extends Model
{
    public const string KIND_THRESHOLD = 'threshold';

    public const string KIND_OVERAGE = 'overage';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'notified_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function hourBank(): BelongsTo
    {
        return $this->belongsTo(HourBank::class);
    }
}
