<?php

namespace App\Models;

use App\Enums\PersonalDataExportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PersonalDataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exportación de los datos personales de una persona (SPEC §15, D-075): ZIP con JSON y CSV,
 * generado en cola en el disco privado y descargado con URL firmada hasta expires_at.
 *
 * @property int $id
 * @property int $subject_user_id
 * @property int|null $requested_by
 * @property PersonalDataExportStatus $status
 * @property string $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $downloaded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $subject
 * @property-read User|null $requester
 */
#[Fillable(['subject_user_id', 'requested_by', 'status', 'disk', 'path', 'size_bytes', 'error', 'started_at', 'finished_at', 'expires_at', 'downloaded_at'])]
class PersonalDataExport extends Model
{
    /** @use HasFactory<PersonalDataExportFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'disk' => 'local',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PersonalDataExportStatus::class,
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Lista para descargar: terminada y sin caducar. */
    public function isDownloadable(?CarbonImmutable $now = null): bool
    {
        return $this->status === PersonalDataExportStatus::Ready
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isAfter($now ?? CarbonImmutable::now()));
    }
}
