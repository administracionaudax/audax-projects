<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una versión de un documento de RR. HH. con lectura registrada (Fase 11, R2; D-354; W-088 y
 * W-108): el **documento de implantación del registro de jornada** (art. 34.9 ET, L-04) y la
 * **política de desconexión digital** (art. 88.3 LOPDGDD, L-10). Cada cambio es una versión nueva
 * (las anteriores se conservan: prueban qué leyó cada persona); mientras sea el borrador de la app,
 * va marcado «pendiente de asesor». Lo escribe App\Domain\People\PeopleDocuments.
 *
 * @property int $id
 * @property string $key
 * @property int $version
 * @property string $title
 * @property string $body
 * @property bool $is_draft
 * @property int|null $published_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $publisher
 */
class PeopleDocument extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_draft' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<PeopleDocumentRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(PeopleDocumentRead::class);
    }
}
