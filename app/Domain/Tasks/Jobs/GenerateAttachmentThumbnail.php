<?php

namespace App\Domain\Tasks\Jobs;

use App\Domain\Tasks\AttachmentStorage;
use App\Models\Attachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Miniatura de un adjunto de imagen (SPEC §2 y §15, RUNBOOK-DESPLIEGUE §1): las imágenes se
 * procesan solo en Horizon, dentro de system-audax.slice (límite de memoria duro), y nunca en la
 * petición web. Se encola al confirmarse la subida (afterCommit).
 * - Un solo intento: la miniatura no es imprescindible (sin ella se ve el icono del tipo) y un
 *   reintento de algo que agotó la memoria volvería a agotarla.
 * - Si el adjunto se borra antes de procesarse, el job se descarta sin error.
 */
final class GenerateAttachmentThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Attachment $attachment) {}

    public function handle(AttachmentStorage $storage): void
    {
        $storage->generateThumbnail($this->attachment);
    }
}
