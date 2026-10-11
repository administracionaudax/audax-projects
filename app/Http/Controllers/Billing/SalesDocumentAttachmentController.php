<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Tasks\AttachmentStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreAttachmentsRequest;
use App\Models\Attachment;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adjuntos de una factura propia (contratos, albaranes; PLAN-EMISION §3.1, H-122; E2, D-444), con el
 * sistema de adjuntos de siempre (AttachmentStorage: disco privado, tipo real, miniatura en cola).
 * Cada uno es interno o «visible para el cliente»: los visibles van en el email y en el portal. No es
 * fiscal: se añaden y se quitan también en una emitida (use-invoicing, matriz `attach`).
 */
class SalesDocumentAttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    public function store(StoreAttachmentsRequest $request, SalesDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'attach'), 403);

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $request->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
        $visible = $request->boolean('client_visible');
        $stored = [];

        try {
            foreach ($files as $file) {
                $attachment = $this->storage->store($file, $document, null, $user);
                if ($visible) {
                    $attachment->forceFill(['client_visible' => true])->save();
                }
                $stored[] = $attachment;
            }
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                rescue(fn () => $this->storage->delete($attachment), report: false);
            }

            throw $exception;
        }

        activity('invoicing')->causedBy($user)->performedOn($document)->event('attachments_added')
            ->withProperties(['attachments' => array_map(fn (Attachment $one): int => $one->id, $stored), 'client_visible' => $visible])
            ->log('invoicing.attachments_added');
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('invoicing.flash.attachments_added', count($stored), ['count' => count($stored)])]);

        return back();
    }

    public function show(Request $request, SalesDocument $document, Attachment $attachment): StreamedResponse
    {
        $this->ownedBy($document, $attachment);
        abort_unless(DocumentActions::allows($document, $request->user(), 'view'), 403);

        return self::serve($attachment);
    }

    public function update(Request $request, SalesDocument $document, Attachment $attachment): RedirectResponse
    {
        $this->ownedBy($document, $attachment);
        abort_unless(DocumentActions::allows($document, $request->user(), 'attach'), 403);
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);

        $attachment->forceFill(['client_visible' => (bool) $data['client_visible']])->save();
        activity('invoicing')->causedBy($request->user())->performedOn($document)->event('attachment_visibility')
            ->withProperties(['attachment' => $attachment->id, 'client_visible' => $attachment->client_visible])
            ->log('invoicing.attachment_visibility');
        Inertia::flash('toast', ['type' => 'success', 'message' => __($attachment->client_visible ? 'invoicing.flash.attachment_visible' : 'invoicing.flash.attachment_internal')]);

        return back();
    }

    public function destroy(Request $request, SalesDocument $document, Attachment $attachment): RedirectResponse
    {
        $this->ownedBy($document, $attachment);
        abort_unless(DocumentActions::allows($document, $request->user(), 'attach'), 403);

        $this->storage->delete($attachment);
        activity('invoicing')->causedBy($request->user())->performedOn($document)->event('attachment_deleted')
            ->withProperties(['attachment' => $attachment->id, 'name' => $attachment->original_name])
            ->log('invoicing.attachment_deleted');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.attachment_deleted')]);

        return back();
    }

    /**
     * Sirve un adjunto como la ruta de siempre: solo las imágenes en el navegador; lo demás, descarga.
     */
    public static function serve(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);
        $inline = $attachment->isPreviewableImage();

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    private function ownedBy(SalesDocument $document, Attachment $attachment): void
    {
        abort_unless($attachment->attachable_type === $document->getMorphClass() && $attachment->attachable_id === $document->id, 404);
    }
}
