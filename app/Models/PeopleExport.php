<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada fichero del registro de jornada que sale de la app (Fase 11, R2; D-351): «Mi registro», los
 * informes, los cierres, la exportación para la Inspección… con su SHA-256, quién lo pidió (una
 * persona o un acceso de la Inspección) y con qué parámetros. Sirve para comprobar después que un
 * fichero no se ha tocado (`/personas/inspeccion`, «Comprobar un fichero»). También queda en la
 * auditoría.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $inspection_access_id
 * @property string $kind
 * @property string $format
 * @property array<string, mixed> $params
 * @property string $filename
 * @property string $sha256
 * @property string|null $content_hash
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 * @property-read InspectionAccess|null $inspectionAccess
 */
class PeopleExport extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<InspectionAccess, $this>
     */
    public function inspectionAccess(): BelongsTo
    {
        return $this->belongsTo(InspectionAccess::class);
    }
}
