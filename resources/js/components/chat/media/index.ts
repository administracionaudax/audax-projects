/**
 * Audios, adjuntos, transcripciones y búsqueda del chat (Fase 6, área C3). API estable para C1:
 *
 * ── Pintar un mensaje ──────────────────────────────────────────────────────────────────────────
 * En el servidor, cada mensaje lleva lo multimedia con `MediaPayload::of($message)`
 * (App\Http\Controllers\Chat\Media\MediaPayload; cargar antes `MediaPayload::RELATIONS`):
 *   { attachments: ChatAttachment[], audio: ChatAudio | null, transcription: ChatTranscription | null }
 * Un mensaje borrado u ocultado no lleva nada; quien modera puede pedirlo con `reveal: true`.
 *
 *   <AttachmentList attachments={message.attachments} />
 *     Imágenes en miniatura con visor accesible (Escape, flechas); PDF y demás como tarjeta con
 *     icono, nombre y tamaño; SVG siempre como descarga.
 *   {message.audio && <AudioMessage message={message} />}
 *     Reproductor (play/pausa, barra con teclado, duración, 1× / 1,5× / 2×) y la transcripción
 *     plegable con «Copiar», «Transcribiendo…», «Transcripción pendiente» o «Sin voz». Se
 *     actualiza sola: evento AUDIO_TRANSCRIBED_EVENT del canal conversation.{id} si hay tiempo
 *     real y una consulta ligera (una para todos los audios pendientes) si no.
 *     `defaultTranscriptOpen` la abre (p. ej. al llegar desde la búsqueda: ?mensaje={id}).
 *
 * ── Escribir ───────────────────────────────────────────────────────────────────────────────────
 *   const media = useChatMediaComposer(conversation.id, { onSent: (message) => … });
 *   <AttachmentDropzone onFiles={media.addFiles}>   ← envuelve la conversación: soltar y pegar
 *     …mensajes y editor…
 *     <MediaComposerTray composer={media} />        ← pendientes, progreso, cancelar, reintentar
 *     <AttachFilesButton onFiles={media.addFiles} /> ← clip con el selector de archivos
 *     <AudioRecorder onRecorded={(file, durationMs) => void media.sendAudio(file, durationMs, parentId)} />
 *   </AttachmentDropzone>
 *   Al enviar: si hay archivos (media.files.length > 0), `await media.send({ body, parentId })`
 *   publica texto y archivos en un solo mensaje; si no, el envío de texto de C1.
 *
 *   También, sin estado: `sendWithMedia(conversationId, { body, files, audio: { file, durationMs },
 *   parentId }, { onProgress, signal })` → Promise<SentMediaMessage> (rechaza con MediaUploadError).
 *   Ruta: POST /chat/{conversación}/multimedia (chat.media.store). Todo pasa por MessageWriter.
 *
 * ── Tiempo real (C2) ───────────────────────────────────────────────────────────────────────────
 *   AudioMessage escucha en `conversation.{id}` el evento AUDIO_TRANSCRIBED_EVENT
 *   ('.audio.transcribed', broadcastAs 'audio.transcribed'). Como todos los de C2, solo lleva ids
 *   ({ conversation_id, message_id, transcription_id, status }): el estado y el texto se piden a
 *   GET /chat/transcripciones?mensajes={id} (chat.media.transcriptions), que comprueba los permisos.
 *
 * ── Búsqueda ───────────────────────────────────────────────────────────────────────────────────
 *   /chat/buscar?q=&conversacion=&tipo=mensajes|archivos|audios (chat.search). Cada resultado
 *   enlaza a /chat/{conversación}?mensaje={id}: C1 abre la conversación en ese mensaje.
 *   La búsqueda global (Ctrl+K) incluye los mensajes (App\Search\Sources\MessageSource).
 */

export {
    AttachFilesButton,
    AttachmentDropzone,
} from '@/components/chat/media/attachment-dropzone';
export {
    AttachmentList,
    ImageViewer,
} from '@/components/chat/media/attachment-list';
export { AudioPlayer } from '@/components/chat/media/audio-player';
export {
    AudioRecorder,
    LiveWaveform,
} from '@/components/chat/media/audio-recorder';
export {
    AudioMessage,
    AudioTranscription,
} from '@/components/chat/media/audio-transcription';
export { MediaComposerTray } from '@/components/chat/media/media-composer-tray';
export {
    checkChatFiles,
    formatClock,
    MAX_CHAT_FILES,
    useMediaLimits,
} from '@/components/chat/media/media-utils';
export {
    MediaUploadError,
    mediaFormData,
    sendWithMedia,
} from '@/components/chat/media/send-with-media';
export type {
    MediaAudioPayload,
    MediaMessagePayload,
    MediaUploadErrorKind,
    SendMediaOptions,
} from '@/components/chat/media/send-with-media';
export {
    HighlightedText,
    matchRanges,
} from '@/components/chat/media/text-match';
export type * from '@/components/chat/media/types';
export { useAudioRecorder } from '@/components/chat/media/use-audio-recorder';
export {
    AUDIO_TRANSCRIBED_EVENT,
    isTranscriptionEventFor,
    useLiveTranscription,
    watchTranscription,
} from '@/components/chat/media/use-live-transcription';
export { useChatMediaComposer } from '@/components/chat/media/use-media-composer';
export type { MediaComposer } from '@/components/chat/media/use-media-composer';
