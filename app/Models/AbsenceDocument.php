<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Justificante de una ausencia (Fase 11, R3; W-071; D-368), en el disco privado
 * (`people/justificantes/{persona}/…`). Solo lo ven la persona, RR. HH. y, si el tipo no es de
 * salud, su responsable (App\Domain\Absences\AbsenceDocuments). Cada descarga queda en la auditoría.
 *
 * @property int $id
 * @property int $absence_id
 * @property int $user_id
 * @property int|null $uploaded_by
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property CarbonImmutable|null $created_at
 * @property-read Absence $absence
 * @property-read User|null $uploader
 */
class AbsenceDocument extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Absence, $this>
     */
    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
