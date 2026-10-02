<?php

namespace App\Http\Controllers\Chat\Media;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Audios del chat (SPEC §12): GET /chat/audios/{adjunto} con URL firmada (signed:relative) Y
 * AttachmentPolicy::view (quien ve la conversación; los de mensajes borrados u ocultos, solo quien
 * modera). Se sirven con BinaryFileResponse, que atiende las peticiones Range (206 Partial Content
 * y Accept-Ranges: bytes): Safari no reproduce sin ellas y todos los navegadores las usan para
 * saltar a un punto del audio. Tipo de audio correcto, en línea, sin adivinar el tipo ni ejecutar nada.
 */
class AudioController extends Controller
{
    public function __invoke(Attachment $attachment): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);

        abort_unless($attachment->isAudio(), 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $response = new BinaryFileResponse($disk->path($attachment->path), 200, [
            'Content-Type' => MediaPayload::audioMime($attachment->mime),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ], public: false);

        $response->setContentDisposition(
            'inline',
            $attachment->original_name,
            str_replace(['%', '/', '\\'], '', Str::ascii($attachment->original_name)) ?: 'audio',
        );

        return $response;
    }
}
