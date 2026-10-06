/**
 * Contrato JSON de lo multimedia del chat (Fase 6, área C3) con
 * App\Http\Controllers\Chat\Media\MediaPayload y los controladores de C3.
 */

/** image: rasterizada con miniatura y visor; svg: siempre descarga (D-037); file: el resto. */
export type ChatAttachmentKind = 'image' | 'svg' | 'file';

export type ChatAttachment = {
    id: number;
    original_name: string;
    mime: string;
    size: number;
    kind: ChatAttachmentKind;
    is_image: boolean;
    /** URL firmada (1 h, relativa): en línea para las imágenes, descarga para el resto. */
    url: string;
    /** Miniatura de 400 px (la genera un job; hasta entonces, null). */
    thumbnail_url: string | null;
};

export type ChatAudio = {
    attachment_id: number;
    /** URL firmada (1 h, relativa) de chat.media.audio, con soporte de Range. */
    url: string;
    /** Tipo de audio con el que se sirve (audio/webm, audio/mp4, audio/ogg…). */
    mime: string;
    size: number;
    /** La que mide el transcriptor o, hasta entonces, la que envió el navegador. */
    duration_ms: number | null;
    original_name: string;
};

export type TranscriptionStatus = 'pending' | 'processing' | 'done' | 'failed';

export type ChatTranscription = {
    id: number;
    status: TranscriptionStatus;
    /** Solo cuando está hecha: '' si el audio no tiene voz. */
    text: string | null;
    language: string | null;
};

/** Lo multimedia de un mensaje (MediaPayload::of). */
export type ChatMedia = {
    attachments: ChatAttachment[];
    audio: ChatAudio | null;
    transcription: ChatTranscription | null;
};

/** Lo que necesita <AudioMessage>: cualquier mensaje con estos campos. */
export type AudioMessageData = {
    id: number;
    conversation_id: number;
    audio: ChatAudio | null;
    transcription: ChatTranscription | null;
};

export type ChatMessageType = 'text' | 'audio' | 'file' | 'system';

/** Respuesta de POST /chat/{c}/multimedia (MediaPayload::message). */
export type SentMediaMessage = ChatMedia & {
    id: number;
    conversation_id: number;
    user_id: number | null;
    type: ChatMessageType;
    body: string | null;
    parent_id: number | null;
    /** Instante ISO en UTC. */
    created_at: string | null;
};

/** Cada elemento de GET /chat/transcripciones?mensajes=… */
export type TranscriptionUpdate = {
    id: number;
    audio: ChatAudio | null;
    transcription: ChatTranscription | null;
};

/** Búsqueda del chat (MessageSource::find). */
export type ChatSearchMatch = 'message' | 'file' | 'transcription';

export type ChatConversationType =
    | 'project'
    | 'direct'
    | 'group'
    | 'client'
    | 'team';

export type ChatSearchResult = {
    id: number;
    conversation_id: number;
    conversation: {
        type: ChatConversationType;
        label: string;
        code: string | null;
    };
    author: string | null;
    /** Instante ISO en UTC. */
    created_at: string | null;
    match: ChatSearchMatch;
    /** Fragmento en texto plano con contexto (o el nombre del archivo). */
    excerpt: string;
    file_name: string | null;
    is_audio: boolean;
    /** /chat/{conversación}?mensaje={id} */
    url: string;
};

/** ?tipo= de /chat/buscar. */
export type ChatSearchType = 'mensajes' | 'archivos' | 'audios';

/** Props de la página /chat/buscar (ChatSearchController). */
export type ChatSearchPageProps = {
    query: string;
    filters: { type: ChatSearchType | null; conversation: number | null };
    conversation: {
        id: number;
        type: ChatConversationType;
        label: string;
    } | null;
    results: ChatSearchResult[];
    /** Para seguir la lista (?antes=), o null si no hay más. */
    next: number | null;
    minLength: number;
};

/** Filtro de /admin/transcripciones (?estado=). */
export type AdminTranscriptionFilter =
    | 'pendientes'
    | 'en-curso'
    | 'fallidas'
    | 'hechas';

export type AdminTranscriptionRow = {
    id: number;
    message_id: number;
    status: TranscriptionStatus;
    attempts: number;
    last_error: string | null;
    audio_duration_ms: number | null;
    processing_ms: number | null;
    engine: string | null;
    model: string | null;
    created_at: string | null;
    queued_at: string | null;
    transcribed_at: string | null;
    /** Ya se avisó al admin de que sigue fallando tras agotar los intentos. */
    admin_notified: boolean;
    /** De las directas no se muestran ni las personas ni el enlace (D-071). */
    conversation: { type: ChatConversationType; label: string | null };
    author: string | null;
    message_deleted: boolean;
    message_hidden: boolean;
    /** La duración real (del transcriptor) pasa de la máxima del ajuste. */
    over_limit: boolean;
    can_retry: boolean;
    url: string | null;
};

/** Props de /admin/transcripciones (Admin\TranscriptionController::index). */
export type AdminTranscriptionsPageProps = {
    filters: { status: AdminTranscriptionFilter | null };
    counts: Record<'pending' | 'processing' | 'failed' | 'done', number>;
    maxAudioSeconds: number;
    transcriptions: AdminTranscriptionRow[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
};
