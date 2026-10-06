<?php

namespace App\Domain\Weeklies\Help;

use App\Domain\System\DiskUsage;
use App\Domain\Tasks\AttachmentStorage;
use App\Models\Attachment;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Cuotas de las subidas de la ayuda y las sugerencias (D-223, hallazgo 4 de la revisión de seguridad
 * de la Fase 10), configurables en `config/help.php`:
 *   - el espacio libre mínimo del disco de los adjuntos para aceptar cualquier subida,
 *   - el total de vídeos de tutoriales a medio subir (entre todas las personas),
 *   - lo que cada persona puede tener subido en adjuntos de sugerencias y comentarios.
 * Devuelve el motivo para enseñarlo a la persona, o null si cabe.
 */
final class HelpUploadQuota
{
    private const int MB = 1024 * 1024;

    public function __construct(private readonly DiskUsage $disk) {}

    /** ¿Queda espacio libre en el disco para `$bytes` más el mínimo? */
    public function diskProblem(int $bytes): ?string
    {
        $space = $this->disk->measure(Storage::disk(AttachmentStorage::DISK)->path(''));
        $minimum = max(0, (int) config('help.uploads.min_free_mb', 0)) * self::MB;

        if ($space !== null && $space->freeBytes - $bytes < $minimum) {
            return __('help.quota.disk_full');
        }

        return null;
    }

    /** ¿Cabe un vídeo de `$bytes` entre los que están a medio subir (`$pendingBytes`)? */
    public function tutorialProblem(int $pendingBytes, int $bytes): ?string
    {
        $limit = max(0, (int) config('help.uploads.tutorial_pending_mb', 0)) * self::MB;

        if ($limit > 0 && $pendingBytes + $bytes > $limit) {
            return __('help.quota.tutorials_busy');
        }

        return $this->diskProblem($bytes);
    }

    /** ¿Caben `$bytes` más en los adjuntos de sugerencias de la persona (sin los que quita)? */
    public function suggestionProblem(User $user, int $bytes, int $removedBytes = 0): ?string
    {
        if ($bytes <= 0) {
            return null;
        }

        $limit = max(0, (int) config('help.uploads.suggestion_user_mb', 0));

        if ($limit > 0 && $this->suggestionBytes($user) - $removedBytes + $bytes > $limit * self::MB) {
            return __('help.quota.suggestions_full', ['limit' => $limit]);
        }

        return $this->diskProblem($bytes);
    }

    /** Lo que la persona tiene subido en sugerencias y comentarios. */
    public function suggestionBytes(User $user): int
    {
        return (int) Attachment::query()
            ->where('user_id', $user->id)
            ->whereIn('attachable_type', [(new SuggestionPost)->getMorphClass(), (new SuggestionComment)->getMorphClass()])
            ->sum('size');
    }
}
