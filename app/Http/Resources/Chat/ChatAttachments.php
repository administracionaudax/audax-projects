<?php

namespace App\Http\Resources\Chat;

use App\Http\Resources\Tasks\AttachmentResource;
use App\Models\Attachment;
use App\Models\AudioTranscription;

/**
 * Adjuntos y audio de un mensaje (contrato: resources/js/types/chat.ts, ChatAttachment y
 * ChatAudio). Las URLs van firmadas (1 h) y el servidor comprueba además la política al servir.
 *
 * INTEGRACIÓN (C3, audios y adjuntos): las URLs salen de aquí y de ningún otro sitio. Hasta que
 * lleguen las rutas propias del chat (chat.media.*, con Range para los audios y la política de la
 * conversación), se usan las de la Fase 1 (attachments.show y attachments.thumbnail).
 */
final class ChatAttachments
{
    /**
     * @return array{id: int, original_name: string, mime: string, size: int, is_image: bool, url: string, thumbnail_url: string|null}
     */
    public static function present(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'is_image' => $attachment->isPreviewableImage(),
            'url' => AttachmentResource::downloadUrl($attachment),
            'thumbnail_url' => AttachmentResource::thumbnailUrl($attachment),
        ];
    }

    /**
     * El audio de un mensaje de audio, con su transcripción obligatoria (D-070).
     *
     * @return array{id: int, url: string, mime: string, size: int, duration_ms: int|null, transcription: array{status: string, text: string|null, language: string|null}|null}
     */
    public static function audio(Attachment $attachment, ?AudioTranscription $transcription): array
    {
        return [
            'id' => $attachment->id,
            'url' => AttachmentResource::downloadUrl($attachment),
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'duration_ms' => $transcription?->audio_duration_ms,
            'transcription' => $transcription === null ? null : [
                'status' => $transcription->status->value,
                'text' => $transcription->text,
                'language' => $transcription->language,
            ],
        ];
    }
}
