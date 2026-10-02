/**
 * Chat (Fase 6, área C1). Contrato con app/Http/Resources/Chat/* (ConversationList,
 * ConversationPresenter, MessagePresenter, PinnedPresenter) y los controladores de
 * app/Http/Controllers/Chat. Instantes en ISO 8601 (UTC).
 */
import type {
    ChatAttachment,
    ChatAudio,
    ChatTranscription,
} from '@/components/chat/media/types';
import type { Project } from '@/types/domain';

export type ChatConversationType = 'project' | 'direct' | 'group';

export type ChatMessageType = 'text' | 'audio' | 'file' | 'system';

/** Persona del chat (autor, participante, mencionada). Las inactivas siguen en el histórico. */
export type ChatUser = {
    id: number;
    name: string;
    avatar: string | null;
    is_active: boolean;
};

export type ChatProjectSummary = {
    id: number;
    code: string;
    name: string;
    color: string;
    status: string;
};

/** Mensaje de sistema: la interfaz pinta el aviso con su clave y sus datos. */
export type ChatSystemData = {
    key: string;
    payload: Record<string, unknown>;
};

export type ChatLastMessage = {
    id: number;
    kind: ChatMessageType | 'hidden';
    author: string | null;
    is_mine: boolean;
    /** Texto plano, sin markdown (lo ocultado y lo de sistema, vacío). */
    preview: string;
    system: ChatSystemData | null;
    created_at: string;
};

/** Fila de la lista de conversaciones (/chat). */
export type ChatConversationItem = {
    id: number;
    type: ChatConversationType;
    title: string;
    subtitle: string | null;
    project: ChatProjectSummary | null;
    other_user: ChatUser | null;
    members_count: number;
    muted: boolean;
    unread: number;
    /** Proyecto archivado: se lee pero no se escribe. */
    read_only: boolean;
    last_message: ChatLastMessage | null;
    last_activity_at: string | null;
};

export type ChatParticipant = ChatUser & {
    last_read_message_id: number | null;
};

export type ChatReadOnlyReason = 'archived' | 'not_participant' | 'inactive';

/** Conversación abierta (cabecera, participantes y permisos). */
export type ChatConversation = {
    id: number;
    type: ChatConversationType;
    title: string;
    subtitle: string | null;
    project: ChatProjectSummary | null;
    other_user: ChatUser | null;
    participants: ChatParticipant[];
    muted: boolean;
    is_participant: boolean;
    /** Lo leído por quien mira al abrir (separador «Mensajes nuevos»). */
    last_read_message_id: number | null;
    can: {
        post: boolean;
        moderate: boolean;
        create_task: boolean;
        mute: boolean;
        /** Grupos (D-119): renombrar y añadir o quitar personas (quien lo creó o el admin). */
        manage: boolean;
        /** Grupos: salir (quien participa). */
        leave: boolean;
    };
    read_only_reason: ChatReadOnlyReason | null;
};

/** Conversación que el admin modera sin participar (D-071, D-119): proyectos y grupos. */
export type ChatModerationItem = {
    id: number;
    type: Exclude<ChatConversationType, 'direct'>;
    title: string;
    subtitle: string | null;
    members_count: number;
    read_only: boolean;
    last_activity_at: string | null;
};

export type ChatReaction = {
    emoji: string;
    count: number;
    reacted: boolean;
    users: string[];
};

/** Cita del mensaje al que se responde (hilo). */
export type ChatParent = {
    id: number;
    type: ChatMessageType;
    author: string | null;
    excerpt: string | null;
    deleted: boolean;
    hidden: boolean;
    system: ChatSystemData | null;
};

/**
 * Lo multimedia de cada mensaje es el contrato de C3 (MediaPayload::of en el servidor):
 * adjuntos con su tipo, el audio servido con Range y su transcripción obligatoria (D-070).
 */
export type {
    ChatAttachment,
    ChatAudio,
    ChatTranscription,
    TranscriptionStatus as ChatTranscriptionStatus,
} from '@/components/chat/media/types';

export type ChatLinkPreview = {
    url: string;
    title: string;
    description: string | null;
    domain: string;
};

export type ChatMessageAbilities = {
    edit: boolean;
    delete: boolean;
    reply: boolean;
    react: boolean;
    pin: boolean;
    moderate: boolean;
    create_task: boolean;
};

export type ChatMessage = {
    id: number;
    conversation_id: number;
    type: ChatMessageType;
    author: ChatUser | null;
    /** Markdown ligero; null si está borrado, ocultado (salvo para quien modera) o es de sistema. */
    body: string | null;
    created_at: string;
    edited_at: string | null;
    deleted: boolean;
    hidden: boolean;
    /** Quién lo ocultó (solo para quien modera). */
    hidden_by: string | null;
    pinned: boolean;
    pinned_by: string | null;
    parent: ChatParent | null;
    reactions: ChatReaction[];
    /** Adjuntos, audio y transcripción (MediaPayload, C3); vacíos si está borrado u ocultado. */
    attachments: ChatAttachment[];
    audio: ChatAudio | null;
    transcription: ChatTranscription | null;
    task: { id: number; title: string } | null;
    link_preview: ChatLinkPreview | null;
    system: ChatSystemData | null;
    can: ChatMessageAbilities;
    /** Solo en el navegador: envío en curso o fallido (id negativo hasta que responde el servidor). */
    pending?: 'sending' | 'failed';
};

/** Una página de mensajes (del más antiguo al más nuevo). */
export type ChatMessagesPage = {
    messages: ChatMessage[];
    /** Personas citadas en los mensajes (menciones <@ID>), además de los autores. */
    users: ChatUser[];
    has_older: boolean;
    has_newer: boolean;
    /** Instante del servidor al responder: la primera consulta periódica pide cambios desde aquí. */
    server_time: string;
};

export type ChatPinnedMessage = {
    id: number;
    type: ChatMessageType;
    author: string | null;
    excerpt: string;
    system: ChatSystemData | null;
    hidden: boolean;
    pinned_at: string | null;
    pinned_by: string | null;
};

export type ChatReadState = {
    user_id: number;
    last_read_message_id: number | null;
};

/** GET /chat/{id}/novedades. */
export type ChatPollResponse = {
    messages: ChatMessage[];
    updated: ChatMessage[];
    users: ChatUser[];
    has_more: boolean;
    pinned: ChatPinnedMessage[];
    read_state: ChatReadState[];
    server_time: string;
};

/** Respuesta de las acciones sobre un mensaje. */
export type ChatMessageResponse = {
    message: ChatMessage;
    users: ChatUser[];
    pinned?: ChatPinnedMessage[];
    task?: { id: number; title: string; url: string };
};

export type ChatPerson = ChatUser & { department: string | null };

/** GET /chat/mensajes/{id}/tarea: datos del diálogo «Crear tarea». */
export type ChatTaskOptions = {
    title: string;
    project: ChatProjectSummary & { uses_hour_banks: boolean };
    banks: { id: number; name: string; department: string | null }[];
    people: (ChatUser & { is_member: boolean })[];
};

/** Props de la conversación abierta (en /chat/{id} y en la pestaña del proyecto). */
export type ChatConversationProps = {
    conversation: ChatConversation | null;
    messages: ChatMessagesPage | null;
    pinned: ChatPinnedMessage[];
    focus: number | null;
};

/** pages/chat/index.tsx (/chat y /chat/{id}). */
export type ChatIndexPageProps = ChatConversationProps & {
    conversations: ChatConversationItem[];
};

/** pages/chat/project.tsx (/proyectos/{id}/chat). */
export type ChatProjectPageProps = ChatConversationProps & {
    project: Project;
    canManage: boolean;
};

/** Props compartidas (HandleInertiaRequests): total sin leer de la entrada Chat. */
export type ChatSharedProps = {
    unread: number;
};

/** Tarjeta «Menciones» de Inicio (HomeChatSummary, prop diferida `chat_summary`). */
export type HomeChatSummary = {
    /** Total de la navegación (sin las silenciadas). */
    unread_total: number;
    conversations: {
        id: number;
        type: ChatConversationType;
        title: string;
        unread: number;
        url: string;
    }[];
    mentions: {
        id: number;
        conversation_id: number;
        conversation: string;
        author: string | null;
        excerpt: string;
        /** @todos (y no una mención personal). */
        everyone: boolean;
        unread: boolean;
        created_at: string | null;
        /** /chat/{conversación}?mensaje={id} */
        url: string;
    }[];
};
