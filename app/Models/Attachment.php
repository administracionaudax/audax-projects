<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Adjunto polimórfico (SPEC §4.3) en disco privado. Nunca se sirve directamente: se descarga
 * con ruta firmada y comprobación de la política (SPEC §15). Los SVG se descargan siempre (D-037).
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string|null $thumbnail_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model $attachable
 * @property-read Project|null $project
 * @property-read User|null $uploader
 */
#[Fillable(['attachable_type', 'attachable_id', 'project_id', 'user_id', 'disk', 'path', 'original_name', 'mime', 'size', 'thumbnail_path'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Tipos MIME permitidos (D-037): imágenes, PDF, ofimática (Office y ODF), texto, CSV y ZIP.
     */
    public const array ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain', 'text/csv', 'text/markdown',
        'application/zip', 'application/x-zip-compressed',
    ];

    /**
     * Imágenes rasterizadas con miniatura y vista previa en la página (los SVG no).
     */
    public const array PREVIEWABLE_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPreviewableImage(): bool
    {
        return in_array($this->mime, self::PREVIEWABLE_IMAGES, true);
    }
}
