<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\AttachmentStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreAttachmentsRequest;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adjuntos (SPEC §15, D-037). Nunca son públicos:
 * - descarga y miniatura con URL firmada temporal (middleware signed:relative) Y AttachmentPolicy::view,
 * - X-Content-Type-Options: nosniff y Content-Security-Policy: sandbox en todas las respuestas,
 * - solo las imágenes rasterizadas se muestran en el navegador (inline); los SVG y todo lo demás
 *   se descargan siempre (Content-Disposition: attachment).
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /**
     * Subir archivos a una tarea: quien puede editarla (TaskPolicy::update).
     */
    public function store(StoreAttachmentsRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        /** @var User $user */
        $user = $request->user();
        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->storage->store($file, $task, $task->project_id, $user);
            }
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                rescue(fn () => $this->storage->delete($attachment), report: false);
            }

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('tasks.flash.attachments_uploaded', count($stored), ['count' => count($stored)])]);

        return back();
    }

    public function show(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        $inline = $attachment->isPreviewableImage();

        return $this->serve($attachment, $attachment->path, $attachment->original_name, $inline ? $attachment->mime : 'application/octet-stream', $inline);
    }

    public function thumbnail(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        abort_if($attachment->thumbnail_path === null || ! $attachment->isPreviewableImage(), 404);

        $mime = str_ends_with($attachment->thumbnail_path, '.webp') ? 'image/webp' : 'image/png';

        return $this->serve($attachment, $attachment->thumbnail_path, $attachment->original_name, $mime, true);
    }

    /**
     * Borra el adjunto y sus ficheros: quien lo subió, quien gestiona el proyecto o un admin.
     */
    public function destroy(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('delete', $attachment);

        $this->storage->delete($attachment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.attachment_deleted')]);

        return back();
    }

    private function serve(Attachment $attachment, string $path, string $name, string $mime, bool $inline): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }
}
