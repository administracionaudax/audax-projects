<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fichero de un envío que pasaba del límite del adjunto (D-141, 10 MB): queda en el disco privado y
 * el correo lleva un enlace firmado que caduca en expires_at (7 días). El id es un UUID: el enlace
 * no deja adivinar los de otros envíos. Lo borra reports:prune-downloads al caducar.
 *
 * @property string $id
 * @property int $delivery_id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime
 * @property int $size_bytes
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ReportDelivery $delivery
 */
#[Fillable(['delivery_id', 'disk', 'path', 'filename', 'mime', 'size_bytes', 'expires_at'])]
class ReportDownload extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ReportDelivery::class, 'delivery_id');
    }
}
