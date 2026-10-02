<?php

namespace App\Http\Controllers\Chat\Media;

use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Http\Resources\Tasks\AttachmentResource;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Message;
use Illuminate\Support\Facades\URL;

/**
 * Contrato JSON de lo multimedia de un mensaje del chat (resources/js/components/chat/media/types.ts,
 * ChatMedia). Lo usan las respuestas de C3 y lo puede mezclar C1 en cada mensaje que pinta:
 * `[...$base, ...MediaPayload::of($message)]`.
 * - attachments: los adjuntos (imágenes rasterizadas con miniatura y visor; SVG, siempre descarga;
 *   el resto, tarjeta con icono, nombre y tamaño), con la URL firmada de attachments.show,
 * - audio: el audio del mensaje, servido con Range (chat.media.audio), con su tipo y su duración
 *   (la del navegador hasta que el transcriptor mide la real),
 * - transcription: su estado y, cuando está hecha, su texto («» si el audio no tiene voz).
 *
 * Las URLs van firmadas (1 h, relativas) y los controladores comprueban además AttachmentPolicy.
 * Hay que cargar antes RELATIONS (with/load) para no hacer N+1. Un mensaje borrado u ocultado no
 * lleva nada, salvo que quien mira modere la conversación y se pida con $reveal.
 */
final class MediaPayload
{
    /**
     * Relaciones que hay que cargar antes.
     */
    public const array RELATIONS = ['attachments', 'transcription'];

    /**
     * @return array{attachments: list<array<string, mixed>>, audio: array<string, mixed>|null, transcription: array<string, mixed>|null}
     */
    public static function of(Message $message, bool $reveal = false): array
    {
        if (! $reveal && ($message->trashed() || $message->hidden_at !== null)) {
            return ['attachments' => [], 'audio' => null, 'transcription' => null];
        }

        $transcription = $message->transcription;
        $audio = null;
        $attachments = [];

        foreach ($message->attachments->sortBy('id') as $attachment) {
            if ($audio === null && self::isTheAudio($message, $attachment, $transcription)) {
                $audio = self::audio($attachment, $transcription);

                continue;
            }

            $attachments[] = self::attachment($attachment);
        }

        return [
            'attachments' => $attachments,
            'audio' => $audio,
            'transcription' => $transcription === null ? null : self::transcription($transcription),
        ];
    }

    /**
     * El mensaje recién publicado (respuesta de chat.media.store): lo básico y lo multimedia.
     *
     * @return array<string, mixed>
     */
    public static function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'user_id' => $message->user_id,
            'type' => $message->type->value,
            'body' => $message->body,
            'parent_id' => $message->parent_id,
            'created_at' => $message->created_at?->toIso8601ZuluString(),
            ...self::of($message),
        ];
    }

    /**
     * @return array{id: int, original_name: string, mime: string, size: int, kind: 'image'|'svg'|'file', is_image: bool, url: string, thumbnail_url: string|null}
     */
    public static function attachment(Attachment $attachment): array
    {
        $image = $attachment->isPreviewableImage();

        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'kind' => $image ? 'image' : ($attachment->mime === 'image/svg+xml' ? 'svg' : 'file'),
            'is_image' => $image,
            'url' => AttachmentResource::downloadUrl($attachment),
            'thumbnail_url' => $image ? AttachmentResource::thumbnailUrl($attachment) : null,
        ];
    }

    /**
     * @return array{attachment_id: int, url: string, mime: string, size: int, duration_ms: int|null, original_name: string}
     */
    public static function audio(Attachment $attachment, ?AudioTranscription $transcription): array
    {
        return [
            'attachment_id' => $attachment->id,
            'url' => self::audioUrl($attachment),
            'mime' => self::audioMime($attachment->mime),
            'size' => $attachment->size,
            'duration_ms' => $transcription?->audio_duration_ms,
            'original_name' => $attachment->original_name,
        ];
    }

    /**
     * @return array{id: int, status: string, text: string|null, language: string|null}
     */
    public static function transcription(AudioTranscription $transcription): array
    {
        $done = $transcription->status === TranscriptionStatus::Done;

        return [
            'id' => $transcription->id,
            'status' => $transcription->status->value,
            'text' => $done ? ($transcription->text ?? '') : null,
            'language' => $done ? $transcription->language : null,
        ];
    }

    /**
     * URL firmada (relativa, como la de los adjuntos) del audio, con soporte de Range.
     */
    public static function audioUrl(Attachment $attachment): string
    {
        return URL::temporarySignedRoute('chat.media.audio', AttachmentResource::expiresAt(), ['attachment' => $attachment->id], absolute: false);
    }

    /**
     * Tipo con el que el navegador reproduce el audio: fileinfo detecta a veces los contenedores de
     * solo audio como vídeo (webm, mp4) y los ogg como application/ogg.
     */
    public static function audioMime(string $mime): string
    {
        return match ($mime) {
            'video/webm' => 'audio/webm',
            'video/mp4', 'audio/x-m4a' => 'audio/mp4',
            'application/ogg' => 'audio/ogg',
            'audio/x-wav', 'audio/vnd.wave' => 'audio/wav',
            default => $mime,
        };
    }

    /**
     * El audio de un mensaje de audio es el de su transcripción (o, si aún no la tiene, el primer
     * adjunto de audio). Los demás adjuntos se pintan como tales.
     */
    private static function isTheAudio(Message $message, Attachment $attachment, ?AudioTranscription $transcription): bool
    {
        if ($message->type !== MessageType::Audio) {
            return false;
        }

        return $transcription === null ? $attachment->isAudio() : $attachment->id === $transcription->attachment_id;
    }
}
