<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\UserSummaryResource;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Adjunto (contrato: resources/js/types/tasks.ts, TaskAttachment). Las URLs de descarga y de
 * miniatura van firmadas (1 h, relativas a la app) y el controlador comprueba además la política
 * (SPEC §15). Cargar antes `uploader` (y `attachable` si se quiere la tarea, pestaña Archivos).
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * Las URLs caducan entre 60 y 65 minutos después: se redondea a 5 minutos para que la misma
     * URL valga unos minutos y el navegador pueda reutilizar la miniatura en caché.
     */
    public static function expiresAt(): CarbonImmutable
    {
        $step = 300;

        return CarbonImmutable::createFromTimestamp((int) (ceil((now()->getTimestamp() + 3600) / $step) * $step));
    }

    public static function downloadUrl(Attachment $attachment): string
    {
        return URL::temporarySignedRoute('attachments.show', self::expiresAt(), ['attachment' => $attachment->id], absolute: false);
    }

    public static function thumbnailUrl(Attachment $attachment): ?string
    {
        return $attachment->thumbnail_path === null
            ? null
            : URL::temporarySignedRoute('attachments.thumbnail', self::expiresAt(), ['attachment' => $attachment->id], absolute: false);
    }

    /**
     * Lista con `can_delete` para quien mira: quien lo subió, un admin o quien gestiona el proyecto
     * (AttachmentPolicy::delete). $canManageProject se calcula una vez para todo el proyecto.
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return list<array<array-key, mixed>>
     */
    public static function listFor(Collection $attachments, User $viewer, bool $canManageProject): array
    {
        $isAdmin = $viewer->isAdmin();

        return array_values($attachments->map(fn (Attachment $attachment): array => [
            ...Plain::of(new self($attachment)),
            'can_delete' => $isAdmin || $canManageProject || $attachment->user_id === $viewer->id,
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'is_image' => $attachment->isPreviewableImage(),
            'url' => self::downloadUrl($attachment),
            'thumbnail_url' => self::thumbnailUrl($attachment),
            'uploader' => UserSummaryResource::make($this->whenLoaded('uploader')),
            'created_at' => $attachment->created_at?->toIso8601ZuluString(),
            'task' => $this->whenLoaded('attachable', fn () => $this->taskOf($attachment)),
            'in_comment' => $attachment->attachable_type === (new TaskComment)->getMorphClass(),
        ];
    }

    /**
     * @return array{id: int, title: string, project_id: int}|null
     */
    private function taskOf(Attachment $attachment): ?array
    {
        $attachable = $attachment->attachable;
        $task = match (true) {
            $attachable instanceof Task => $attachable,
            $attachable instanceof TaskComment && $attachable->relationLoaded('task') => $attachable->task,
            default => null,
        };

        return $task instanceof Task ? ['id' => $task->id, 'title' => $task->title, 'project_id' => $task->project_id] : null;
    }
}
