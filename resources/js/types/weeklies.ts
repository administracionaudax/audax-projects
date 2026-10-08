/**
 * La Weekly (Fase 10, D-145 a D-150). Contrato JSON con App\Http\Resources\Weeklies\* y con los
 * enums de app/Enums. Fechas de semana "YYYY-MM-DD" (días de Madrid, sin zona); instantes ISO en UTC.
 * El informe estructurado es App\Domain\Weeklies\Report\WeeklyReport::toArray(), el
 * WeeklyStructuredReport de WeeklySync (ws:types.ts) en snake_case.
 */
import type { TaskPriority, UserSummary } from './domain';
import type { ProjectsPaginated } from './projects';
import type { ReportRequestData } from './reports';

// --- Enums (app/Enums) -------------------------------------------------------------------------

/** WeeklyCycleStatus: una sola activa (D-150). */
export type WeeklyCycleStatus = 'active' | 'closed';

/** WeeklyCycleProgress (F-066), WeeklyTiming::cycleProgress(). */
export type WeeklyCycleProgress =
    | 'finished'
    | 'upcoming'
    | 'overdue'
    | 'completed'
    | 'in_progress';

/** WeeklyPersonStatus (F-042 y F-136), WeeklyTiming::personStatus(). */
export type WeeklyPersonStatus =
    | 'upcoming'
    | 'pending'
    | 'overdue'
    | 'submitted'
    | 'submitted_late'
    | 'missed'
    | 'exempt'
    | 'not_required';

/**
 * WeeklyExemptionReason (D-151): waived = renuncia a la exención por ausencia; away = «Estoy
 * fuera» hasta el plazo o después (D-228).
 */
export type WeeklyExemptionReason = 'absence' | 'manual' | 'waived' | 'away';

/** «Estoy fuera» de la Weekly (D-228), WeeklyAway::of(): activo hoy. */
export type WeeklyAwayReason = 'vacation' | 'absent';

export type WeeklyAwayStatus = {
    reason: WeeklyAwayReason;
    since: string | null;
    /** Vuelta (incluida); null = hasta que se quite. */
    until: string | null;
};

export type WeeklyEntrySource = 'text' | 'dictation';

export type WeeklyAudioSectionKind = 'intro' | 'client' | 'outro';

/** WeeklyJobState: informe o audio en la cola `ai`. */
export type WeeklyJobState = 'queued' | 'running' | 'done' | 'failed';

/** WeeklyClientStatus («On Track», «Risk» y «Blocked» en WeeklySync). */
export type WeeklyClientStatus = 'on_track' | 'risk' | 'blocked';

export type AiProvider = 'gemini' | 'google_tts';

export type AiFeature =
    | 'weekly_report'
    | 'satisfaction'
    | 'audio_script'
    | 'speech'
    | 'dictation_transcription'
    | 'transcript_cleanup'
    | 'suggested_tasks'
    | 'client_summary'
    | 'team_activity'
    | 'person_performance'
    | 'person_client_activity'
    | 'assistant';

export type WeeklyReminderChannel = 'app' | 'email' | 'push';

/** Las tres editables (automatic, manual y weekly_closed) y las dos de texto fijo (10.5, D-199). */
export type WeeklyReminderTemplate =
    | 'automatic'
    | 'manual'
    | 'weekly_closed'
    | 'deadline'
    | 'friday';

export type WeeklyEditableTemplate = 'automatic' | 'manual' | 'weekly_closed';

export type WeeklyReminderStatus = 'queued' | 'sent' | 'failed' | 'skipped';

export type DictationContext = 'weekly_entry' | 'task_note';

/** TranscriptionStatus, el mismo del chat. */
export type DictationStatus = 'pending' | 'processing' | 'done' | 'failed';

export type SuggestionStatus =
    | 'open'
    | 'future'
    | 'planned'
    | 'building_now'
    | 'beta'
    | 'completed';

export type SuggestionReaction = 'thumbs_up' | 'rocket' | 'eyes' | 'heart';

/** AppModule (F-177): ajuste `modules`, prop compartida config.modules. */
export type AppModule =
    | 'weeklies'
    | 'project_status'
    | 'help'
    | 'suggestions'
    | 'assistant'
    | 'day_plan'
    | 'forecast'
    | 'people'
    | 'billing';

// --- Semanas, envíos e informe ----------------------------------------------------------------

/** WeeklyCycleResource. */
export type WeeklyCycleSummary = {
    id: number;
    /** «W41-26». */
    number: string;
    /** «Semana 41 (Lun 05/10 - Vie 09/10)». */
    label: string;
    start_date: string;
    end_date: string;
    deadline_date: string;
    status: WeeklyCycleStatus;
    has_report: boolean;
    report_state: WeeklyJobState | null;
    report_generated_at: string | null;
    audio_state: WeeklyJobState | null;
    /** Hay audio completo generado (10.3): hace falta para cerrar (F-089). */
    has_audio: boolean;
    submission_count_at_generation: number | null;
    closed_at: string | null;
    /** Enviadas (no borradores), si se han contado. */
    submissions_count?: number;
};

/** WeeklyMilestone. date: "YYYY-MM-DD" o texto libre de la IA. */
export type WeeklyMilestone = {
    date: string | null;
    label: string;
};

/** Tipo de proyecto en el informe (WeeklyProjectStatus::KIND_*, D-188). */
export type WeeklyProjectKind =
    | 'hour_bank'
    | 'monthly_fee'
    | 'fixed_price'
    | 'time_and_materials';

/** WeeklyProjectSnapshot (F-074, D-148 y D-188): minutos al generar el informe. */
export type WeeklyProjectSnapshot = {
    project_id: number;
    code: string;
    name: string;
    billing_type: WeeklyProjectKind;
    budget_minutes: number | null;
    consumed_minutes: number;
    expected_minutes: number | null;
    /** Consumido − esperado (positivo: por encima). */
    deviation_minutes: number | null;
    /** Horas de la semana del informe (de lunes a domingo). */
    week_minutes: number;
};

/** WeeklyClientUpdate (ws:types.ts WeeklyClientUpdate). */
export type WeeklyClientUpdate = {
    client_id: number | null;
    client_name: string;
    status: WeeklyClientStatus;
    executive_summary: string;
    next_steps: string[];
    milestones: WeeklyMilestone[];
    tags: string[];
    /** Foto de la satisfacción al cerrar (F-094). */
    satisfaction_score: number | null;
    /** false: nadie escribió de él («Sin novedades», F-075). */
    has_reports: boolean;
    projects: WeeklyProjectSnapshot[];
};

/** WeeklyReport (ws:types.ts WeeklyStructuredReport sin audioSections, que van aparte). */
export type WeeklyReport = {
    global_summary: string;
    team_risks: string[];
    client_updates: WeeklyClientUpdate[];
};

/** WeeklyAudioSectionResource. url: ruta firmada al MP3. */
export type WeeklyAudioSection = {
    id: number;
    /** "intro", "client-{id}" u "outro". */
    key: string;
    kind: WeeklyAudioSectionKind;
    client_id: number | null;
    position: number;
    script: string | null;
    duration_ms: number | null;
    generated_at: string | null;
    url: string | null;
};

/** WeeklyCycleDetailResource. */
export type WeeklyCycleDetail = WeeklyCycleSummary & {
    report: WeeklyReport | null;
    /** Texto final en Markdown (copiar, F-082). */
    report_text: string | null;
    report_error: string | null;
    report_edited_at: string | null;
    audio_error: string | null;
    has_full_audio: boolean;
    audio_sections?: WeeklyAudioSection[];
};

/** WeeklyEntryResource. client_id null = «General / Interno». */
export type WeeklyEntry = {
    id: number;
    client_id: number | null;
    client?: { id: number; name: string; icon: string | null } | null;
    project_id: number | null;
    body: string;
    source: WeeklyEntrySource;
    position: number;
};

/** WeeklySubmissionResource: borrador mientras is_submitted es false. */
export type WeeklySubmission = {
    id: number;
    weekly_cycle_id: number;
    user_id: number;
    user?: UserSummary;
    is_submitted: boolean;
    /** Primer envío: se conserva al reenviar (F-052). */
    submitted_at: string | null;
    resubmitted_at: string | null;
    draft_saved_at: string | null;
    entries?: WeeklyEntry[];
};

/** Lo que se envía al guardar el borrador o enviar (WeeklyDraftData::fromArray). */
export type WeeklyEntryInput = {
    client_id: number | null;
    project_id?: number | null;
    body: string;
    source?: WeeklyEntrySource;
};

export type WeeklyDraftInput = {
    entries: WeeklyEntryInput[];
};

/** WeeklyExemptionResource (sin el tipo de ausencia: dato de salud, D-088). */
export type WeeklyExemption = {
    id: number;
    weekly_cycle_id: number;
    user_id: number;
    reason: WeeklyExemptionReason;
    note: string | null;
    created_by: number | null;
    created_at: string | null;
};

/** Fila del estado del equipo de una semana (F-036, F-067 y F-136), WeeklyTeamStatus::for(). */
export type WeeklyTeamMember = {
    user: UserSummary;
    status: WeeklyPersonStatus;
    submitted_at: string | null;
    exemption_reason: Exclude<WeeklyExemptionReason, 'waived'> | null;
    /** Fila de exención que se puede quitar (manual); null en la de ausencia de la semana activa. */
    exemption_id: number | null;
};

/** Recuentos del estado del equipo: expected = deben enviar (sin los exentos). */
export type WeeklyTeamCounts = {
    submitted: number;
    expected: number;
    exempt: number;
    pending: number;
};

/** WeeklyTeamStatus::for(): enviadas, pendientes y exentas, en ese orden y por nombre. */
export type WeeklyTeamStatus = {
    members: WeeklyTeamMember[];
    counts: WeeklyTeamCounts;
};

/** Fila del histórico (F-066), WeeklyOverview. */
export type WeeklyHistoryRow = WeeklyCycleSummary & {
    progress: WeeklyCycleProgress;
    participation: { submitted: number; expected: number; exempt: number };
};

/** Semana activa o última cerrada, destacadas con su equipo (F-065 y F-067). */
export type WeeklyHighlightedCycle = WeeklyHistoryRow & {
    team: WeeklyTeamStatus;
};

/** MyWeeklyStatus::for(): mi weekly de una semana (F-030, F-032, F-053 y F-054). */
export type MyWeeklyStatus = {
    status: WeeklyPersonStatus;
    participates: boolean;
    must_submit: boolean;
    /** absence o manual; null si no estoy exento (o he renunciado). */
    exemption_reason: Exclude<WeeklyExemptionReason, 'waived'> | null;
    /** Mi fila manual o de renuncia (para quitarla con weeklies.exemptions.destroy). */
    exemption_id: number | null;
    /** Hasta cuándo dura (la vuelta de la ausencia o de «Estoy fuera»), con la semana activa. */
    exemption_until?: string | null;
    waived: boolean;
    submission_id: number | null;
    submitted_at: string | null;
    resubmitted_at: string | null;
    draft_saved_at: string | null;
    entries_count: number;
};

/** «Mis clientes» (F-033): los que gestiono (owned) y en los que colaboro (member). */
export type WeeklyMyClient = {
    id: number;
    name: string;
    icon: string | null;
    /** Unido en la Weekly (D-221): es lo único que se puede dejar desde aquí. */
    subscribed: boolean;
    projects: { id: number; code: string; name: string }[];
};

/**
 * «Unirme a clientes» (F-034, D-221): prop opcional `joinable_clients`, con los códigos de sus
 * proyectos abiertos para buscarlos. Unirse NO da acceso a los proyectos.
 */
export type WeeklyJoinableClient = {
    id: number;
    name: string;
    icon: string | null;
    projects: { id: number; code: string; name: string }[];
};

/** Fila de «Mis weeklies» (F-042), MyWeeklyHistory. */
export type MyWeeklyRow = {
    cycle: WeeklyCycleSummary;
    status: WeeklyPersonStatus;
    is_upcoming: boolean;
    submitted_at: string | null;
    /** Borrador sin enviar con algún apunte. */
    has_draft: boolean;
    entries_count: number;
    exemption_reason: Exclude<WeeklyExemptionReason, 'waived'> | null;
};

/** Cliente del catálogo de «Mi weekly» (F-045), MyWeeklyClients. */
export type WeeklyClientOption = {
    id: number;
    name: string;
    icon: string | null;
    is_active: boolean;
    projects: { id: number; code: string; name: string; is_mine: boolean }[];
};

/** «Mi weekly» de una semana (?semana={id}), MySpaceController::editor(). */
export type MyWeeklyEditor = {
    cycle: WeeklyCycleSummary;
    me: MyWeeklyStatus;
    submission: WeeklySubmission | null;
    /** proposed: ids de los clientes con caja de entrada (D-150); catalog: «Añadir otro cliente». */
    clients: { proposed: number[]; catalog: WeeklyClientOption[] };
    /** «Autocompletar» (F-048): texto por cliente; clave «general» = General / Interno. */
    autofill: Record<string, string>;
    read_only: boolean;
    can: { write: boolean; waive: boolean; undo_waiver: boolean };
};

/** Tarjeta «Weekly» de Inicio (F-030 a F-040), HomeWeeklyCard; null = sin tarjeta. */
export type HomeWeeklyCard = {
    cycle: WeeklyCycleSummary | null;
    me: MyWeeklyStatus | null;
    streak: WeeklyStreakSummary;
    /** Solo para quien gestiona: recuentos y hasta 8 personas pendientes. */
    team: { counts: WeeklyTeamCounts; pending: UserSummary[] } | null;
    can: { manage: boolean; open: boolean };
};

/** Evento `dictation.updated` del canal privado App.Models.User.{id} (D-158). */
export type DictationUpdatedEvent = {
    dictation_id: number;
    status: DictationStatus;
    warning: string | null;
};

/** StreakCalculator::summary() (F-028 y F-031). */
export type WeeklyStreakSummary = {
    submitted: number;
    on_time: number;
    streak: number;
    /** «Constancia (últimas 12 semanas)» (D-233), en el perfil. */
    consistency?: WeeklyConsistencyCell[];
};

/** WeeklyStreaks::consistency (D-233). */
export type WeeklyConsistencyState =
    | 'on_time'
    | 'late'
    | 'missed'
    | 'exempt'
    | 'pending';

export type WeeklyConsistencyCell = {
    cycle_id: number;
    number: string;
    label: string;
    state: WeeklyConsistencyState;
};

/** ClientSatisfactionResource (F-132). */
export type ClientSatisfactionPoint = {
    client_id: number;
    weekly_cycle_id: number;
    score: number;
    previous_score: number | null;
    delta: number;
    rule: string | null;
    reasoning: string | null;
    created_at: string | null;
};

/** DictationResource (D-152): «Transcribiendo…» hasta status done. */
export type Dictation = {
    id: number;
    context: DictationContext;
    status: DictationStatus;
    client_id: number | null;
    task_id: number | null;
    text: string | null;
    /** no_speech, too_short… */
    warning: string | null;
    created_at: string | null;
};

/** AiUsageResource (F-173 y F-180). Coste en USD como texto decimal ("0.000800"). */
export type AiUsageRow = {
    id: number;
    provider: AiProvider;
    model: string;
    feature: AiFeature;
    operation: string | null;
    status: 'success' | 'error';
    latency_ms: number | null;
    prompt_tokens: number | null;
    response_tokens: number | null;
    total_tokens: number | null;
    character_count: number | null;
    estimated_cost_usd: string | null;
    error: string | null;
    user?: UserSummary;
    created_at: string | null;
};

/** Regla de recordatorio (F-101 y F-102): día ISO 1-7 y hora "HH:MM" de Madrid. */
export type WeeklyReminderRule = {
    id: number;
    channel: WeeklyReminderChannel;
    day_of_week: number;
    time: string;
    enabled: boolean;
    position: number;
};

/** Plantilla editable (setting weekly_email_templates); variables {nombre}, {semana} y {weekly_url}. */
export type WeeklyEmailTemplate = {
    subject: string;
    body: string;
};

/** Registro de un aviso (F-108). */
export type WeeklyReminderLogRow = {
    id: number;
    weekly_cycle_id: number | null;
    /** Número de la semana («W41-26»), o null si se borró. */
    cycle_number: string | null;
    user_id: number | null;
    recipient_name: string | null;
    recipient_email: string | null;
    template: WeeklyReminderTemplate;
    channel: WeeklyReminderChannel;
    status: WeeklyReminderStatus;
    error: string | null;
    /** Quién lo envió a mano (null: automático). */
    sent_by_name: string | null;
    created_at: string | null;
};

/** Plantilla en uso, con si es la de por defecto (10.5). */
export type WeeklyTemplateState = WeeklyEmailTemplate & { is_default: boolean };

/** Regla tal como se edita (sin id, una nueva). */
export type WeeklyReminderRuleInput = {
    id: number | null;
    channel: WeeklyReminderChannel;
    day_of_week: number;
    time: string;
    enabled: boolean;
};

/** /weeklies/avisos (WeeklyReminderController::edit, 10.5, D-199 a D-201). */
export type WeeklyRemindersPageProps = {
    cycle: {
        id: number;
        number: string;
        label: string;
        deadline_date: string;
    } | null;
    rules: WeeklyReminderRule[];
    templates: Record<WeeklyEditableTemplate, WeeklyTemplateState>;
    defaults: Record<WeeklyEditableTemplate, WeeklyEmailTemplate>;
    /** {nombre}, {semana}, {week_label} y {weekly_url}. */
    variables: string[];
    friday: {
        /** La weekly en el recordatorio de los viernes (weekly_friday_reminder). */
        weekly: boolean;
        /** El recordatorio de las horas (week_reminder_enabled, en /admin/ajustes). */
        hours: boolean;
    };
    /** Quién debe enviar la semana activa y aún no lo ha hecho. */
    pending: UserSummary[];
    logs: ProjectsPaginated<WeeklyReminderLogRow>;
    filters: {
        template: WeeklyReminderTemplate | null;
        status: WeeklyReminderStatus | null;
    };
    push_available: boolean;
    can: { send: boolean };
};

// --- Centro de ayuda (10.7) -------------------------------------------------------------------

export type HelpLikeUser = { id: number; name: string; avatar: string | null };

/** Estado de una novedad en el listado (F-152): la más reciente cerrada es la nueva. */
export type HelpUpdateStatus = 'new' | 'in_progress' | 'previous';

export type HelpReleaseChange = {
    id: number;
    description: string;
    position: number;
};

/** Novedad automática «V.serie.mes.semana» (F-150). */
export type HelpRelease = {
    id: number;
    major_version: number;
    month_number: number;
    week_of_month: number;
    /** «V.1.10.2». */
    version: string;
    summary: string;
    is_hidden: boolean;
    changes: HelpReleaseChange[];
};

/** Actualización a mano (F-151); body en el formato de RichText. */
export type HelpManualUpdate = {
    id: number;
    published_on: string;
    title: string;
    subtitle: string;
    body: string;
};

/** Una entrada del listado de novedades (HelpCenter::updates). */
export type HelpUpdateEntry = {
    /** «release:12» o «update:3». */
    key: string;
    kind: 'release' | 'manual';
    id: number;
    published_on: string;
    title: string;
    subtitle: string;
    version: string | null;
    status: HelpUpdateStatus;
    release: HelpRelease | null;
    manual: HelpManualUpdate | null;
    likes: HelpLikeUser[];
    liked_by_me: boolean;
};

/** Versiones para elegir en un tutorial y gestionar (también las ocultas). */
export type HelpReleaseOption = {
    id: number;
    version: string;
    major_version: number;
    month_number: number;
    week_of_month: number;
    is_hidden: boolean;
};

/** Tutorial en vídeo (F-155); video_url: ruta firmada (relativa), con Range. */
export type HelpTutorial = {
    id: number;
    title: string;
    description: string | null;
    help_release_id: number | null;
    version: string | null;
    position: number;
    video_url: string | null;
    video_name: string | null;
    video_size: number | null;
    video_mime: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type HelpFaq = {
    id: number;
    help_faq_section_id: number;
    question: string;
    /** HTML de RichText. */
    answer: string;
    position: number;
};

export type HelpFaqSection = {
    id: number;
    name: string;
    position: number;
    faqs: HelpFaq[];
};

/** Settings help_support_url y help_manual (F-157); manual.url, ruta firmada. */
export type HelpSettings = {
    support_url: string | null;
    manual: { name: string; size: number; url: string } | null;
};

// --- Sugerencias (10.7) -----------------------------------------------------------------------

export type SuggestionCategory = {
    id: number;
    suggestion_board_id: number;
    name: string;
    slug: string;
    description: string | null;
    position: number;
    is_active: boolean;
    post_count: number;
};

export type SuggestionBoard = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    position: number;
    is_active: boolean;
    post_count: number;
    categories: SuggestionCategory[];
};

export type SuggestionAttachment = {
    id: number;
    name: string;
    mime: string;
    size: number;
    is_image: boolean;
    /** Ruta firmada de attachments.show. */
    url: string;
    thumbnail_url: string | null;
};

export type SuggestionStatusEvent = {
    id: number;
    from_status: SuggestionStatus | null;
    to_status: SuggestionStatus;
    note: string | null;
    changed_by: UserSummary | null;
    created_at: string | null;
};

export type SuggestionComment = {
    id: number;
    parent_id: number | null;
    author: UserSummary;
    /** HTML de RichText (con menciones). */
    body: string;
    edited_at: string | null;
    created_at: string | null;
    attachments: SuggestionAttachment[];
    reactions: { reaction: SuggestionReaction; user: UserSummary }[];
    replies: SuggestionComment[];
};

/** Sugerencia en el feed y el roadmap (F-162 y F-168). */
export type SuggestionPost = {
    id: number;
    suggestion_board_id: number;
    suggestion_category_id: number | null;
    category: { id: number; name: string; slug: string } | null;
    author: UserSummary;
    title: string;
    slug: string;
    /** Texto plano, 180 caracteres como mucho. */
    preview: string;
    status: SuggestionStatus;
    position: number;
    vote_count: number;
    comment_count: number;
    voted_by_me: boolean;
    last_activity_at: string | null;
    created_at: string | null;
};

/** El detalle (F-164 a F-167). */
export type SuggestionPostDetail = SuggestionPost & {
    body: string;
    board: { id: number; name: string; slug: string };
    attachments: SuggestionAttachment[];
    voters: UserSummary[];
    comments: SuggestionComment[];
    status_events: SuggestionStatusEvent[];
    /** interact: votar, comentar y reaccionar (false en un tablero oculto, D-226). */
    can: {
        update: boolean;
        delete: boolean;
        moderate: boolean;
        interact: boolean;
    };
};

export type SuggestionRoadmapStatus = Exclude<
    SuggestionStatus,
    'open' | 'future'
>;

export type SuggestionFeedOrder =
    | 'trending'
    | 'top'
    | 'new'
    | SuggestionRoadmapStatus;

export type SuggestionPage = {
    items: SuggestionPost[];
    total: number;
    has_more: boolean;
};

export type SuggestionRoadmapColumn = SuggestionPage & {
    status: SuggestionRoadmapStatus;
};

/** Persona que se puede mencionar (F-165). */
export type SuggestionPerson = {
    id: number;
    name: string;
    avatar: string | null;
};

/** La pestaña «Sugerencias» (SuggestionBoardView::page). */
export type SuggestionsTabProps = {
    view: 'roadmap' | 'feedback';
    filters: {
        q: string | null;
        board: number | null;
        category: number | null;
        order: SuggestionFeedOrder;
        statuses: SuggestionRoadmapStatus[];
        limit: number;
    };
    boards: SuggestionBoard[];
    bugs_category_id: number | null;
    feed: SuggestionPage | null;
    roadmap: SuggestionRoadmapColumn[] | null;
    post: SuggestionPostDetail | null;
    people: SuggestionPerson[];
    composer: 'default' | 'bug' | null;
    can: { create: boolean; manage_boards: boolean; moderate: boolean };
};

// --- Páginas (Inertia) ------------------------------------------------------------------------

/** weeklies/index (WeeklyCycleController::index), ?pestana=resumen|historico. */
export type WeekliesIndexPageProps = {
    tab: 'resumen' | 'historico';
    active: WeeklyHighlightedCycle | null;
    latest_closed: WeeklyHighlightedCycle | null;
    cycles: WeeklyHistoryRow[];
    /** Mi weekly de la semana activa; null sin semana activa. */
    me: MyWeeklyStatus | null;
    streak: WeeklyStreakSummary;
    my_clients: { owned: WeeklyMyClient[]; member: WeeklyMyClient[] };
    /** Opcional: se pide con router.reload({ only: ['joinable_clients'] }). */
    joinable_clients?: WeeklyJoinableClient[];
    can: {
        manage: boolean;
        create: boolean;
        extendDeadline: boolean;
        delete: boolean;
        exempt: boolean;
        /** «Recordar» a una persona pendiente (10.5, F-037). */
        remind: boolean;
        /** La pestaña «Avisos» (10.5): quien gestiona la Weekly. */
        reminders: boolean;
    };
};

/** Un reporte original de un cliente en la semana (F-078, «Ver reportes»). */
export type WeeklyOriginalReport = {
    author: UserSummary;
    body: string;
    submitted_at: string | null;
    project_id: number | null;
};

/** Paso de un trabajo de IA en marcha (WeeklyJobProgress, D-190). */
export type WeeklyJobDetail = {
    step: 'clients' | 'summary' | 'scripts' | 'speech' | null;
    done: number;
    total: number;
};

/** Evento `weekly.progress` del canal privado weeklies.{id} (D-190). */
export type WeeklyGenerationEvent = {
    cycle_id: number;
    kind: 'report' | 'audio';
    state: WeeklyJobState;
    step: WeeklyJobDetail['step'];
    done: number;
    total: number;
    error: string | null;
};

/** GET weeklies.report.status: el estado del informe y del audio mientras se generan. */
export type WeeklyReportStatus = {
    report_state: WeeklyJobState | null;
    report_error: string | null;
    report_progress: WeeklyJobDetail | null;
    audio_state: WeeklyJobState | null;
    audio_error: string | null;
    audio_progress: WeeklyJobDetail | null;
    has_report: boolean;
    has_audio: boolean;
    submitted_count: number;
    submission_count_at_generation: number | null;
    stale: boolean;
    report_generated_at: string | null;
    audio_generated_at: string | null;
};

/** Lo que bloquea el cierre (WeeklyReportState::closeBlockers). */
export type WeeklyCloseBlocker = 'not_active' | 'report' | 'audio';

/** weeklies/show (WeeklyCycleController::show, 10.3). */
export type WeeklyShowPageProps = {
    cycle: WeeklyCycleDetail;
    team: WeeklyTeamStatus;
    /** Reportes originales por cliente (clave: id del cliente o «general»). */
    reports: Record<string, WeeklyOriginalReport[]>;
    /** Hay más envíos que al generar el informe («Hay nuevos reportes», F-072). */
    stale: boolean;
    submitted_count: number;
    /** Clientes de mis proyectos («Solo mis proyectos», F-080). */
    my_client_ids: number[];
    progress: { report: WeeklyJobDetail | null; audio: WeeklyJobDetail | null };
    close: { blockers: WeeklyCloseBlocker[]; pending: number };
    /** Para el menú «Exportar ▾» (ReportKind weekly, D-192). */
    report_request: ReportRequestData;
    can: {
        generate: boolean;
        edit: boolean;
        extendDeadline: boolean;
        close: boolean;
        delete: boolean;
    };
};

/** Fila de las sumas de «Uso de IA» (D-193). Coste en USD como texto con 6 decimales. */
export type AiUsageTotals = {
    calls: number;
    errors: number;
    prompt_tokens: number;
    response_tokens: number;
    total_tokens: number;
    characters: number;
    cost_usd: string;
};

/** admin/ai-usage (AiUsageController, F-173 y F-180). */
export type AiUsagePageProps = {
    range: { days: number; options: number[] };
    totals: AiUsageTotals;
    by_feature: (AiUsageTotals & { feature: AiFeature })[];
    by_model: (AiUsageTotals & { provider: AiProvider; model: string })[];
    by_day: { date: string; calls: number; cost_usd: string }[];
    recent: AiUsageRow[];
};

/** my-space/index (MySpaceController::index), ?pestana=reportes|tareas&semana={id}. */
export type MySpacePageProps = {
    tab: 'reportes' | 'tareas';
    /** La semana activa y mi weekly de esa semana (contrato 10.1). */
    cycle: WeeklyCycleSummary | null;
    submission: WeeklySubmission | null;
    weeks: MyWeeklyRow[];
    streak: WeeklyStreakSummary;
    /** Solo con ?semana={id}. */
    editor: MyWeeklyEditor | null;
    /** Pestaña «Tareas» (10.6, F-055 a F-063): con otra pestaña, null. */
    my_tasks: MySpaceTask[] | null;
    task_projects: MySpaceTaskProject[] | null;
    task_statuses: { open: number | null; done: number | null } | null;
    suggestions: TaskSuggestionBatch | null;
    /** La última weekly cerrada, de la que salen las tareas sugeridas (F-062). */
    suggestion_source: WeeklyCycleRef | null;
};

/** Una semana, en corto. */
export type WeeklyCycleRef = { id: number; number: string; label: string };

/** Una tarea de «Mi espacio» (MySpaceTasks::list, D-203): asignada a mí, con mi archivado personal. */
export type MySpaceTask = {
    id: number;
    title: string;
    priority: TaskPriority;
    due_date: string | null;
    created_at: string | null;
    completed: boolean;
    status: {
        id: number;
        name: string;
        category: 'todo' | 'in_progress' | 'done';
    };
    project: { id: number; code: string; name: string };
    client: { id: number; name: string; icon: string | null } | null;
    /** Quién la creó, si no fui yo («De: …»). */
    assigner: { id: number; name: string } | null;
    /** La descripción en texto plano (F-060). */
    notes: string;
    /** false: la descripción tiene formato y se edita en la tarea. */
    notes_editable: boolean;
    archived: boolean;
    /** Tiene horas: no se puede borrar (D-037), se archiva. */
    has_time?: boolean;
    can: { update: boolean; delete: boolean };
};

/** Un proyecto en el que puedo crear tareas (MySpaceTasks::catalog). */
export type MySpaceTaskProject = {
    id: number;
    code: string;
    name: string;
    client: { id: number; name: string; icon: string | null } | null;
    uses_banks: boolean;
    banks: { id: number; name: string; department_id: number | null }[];
};

/** Una tarea propuesta por la IA (F-062, D-204): aún no es una tarea. */
export type TaskSuggestion = {
    key: string;
    title: string;
    client_id: number | null;
    client_name: string | null;
    /** El proyecto y la bolsa sugeridos; la persona los revisa. */
    project_id: number | null;
    hour_bank_id: number | null;
    author_id: number | null;
    author_name: string | null;
};

/** TaskSuggester::present(): la última tanda de propuestas. */
export type TaskSuggestionBatch = {
    state: WeeklyJobState;
    /** En cola o generando, pero sin cambios desde hace más de 12 minutos. */
    stuck: boolean;
    cycle: WeeklyCycleRef | null;
    items: TaskSuggestion[];
    /** Propuestas repetidas que se han quitado. */
    skipped: number;
    error: string | null;
    generated_at: string | null;
};

/** assistant/index (AssistantController::index, F-146 y F-147). */
export type AssistantPageProps = {
    suggested_questions: string[];
    /** Qué entra en el contexto de quien pregunta (D-205). */
    scope: {
        weeklies: boolean;
        clients: boolean;
        project_status: boolean;
        hours: 'own' | 'team' | 'all';
        financials: boolean;
    };
    max_question: number;
};

/** AssistantQuestions::present(): una pregunta y su respuesta (D-206). */
export type AssistantQuestion = {
    id: string;
    state: WeeklyJobState;
    answer: string | null;
    error: string | null;
};

/** Evento `assistant.answered` del canal privado App.Models.User.{id}. */
export type AssistantAnsweredEvent = { question_id: string; state: string };

/** help/index (HelpController::index). */
export type HelpTab = 'general' | 'tutoriales' | 'preguntas' | 'sugerencias';

export type HelpPageProps = {
    tab: HelpTab;
    can: { manage: boolean; suggestions: boolean };
    settings: HelpSettings;
    /** Pestaña General; null en las demás. */
    updates: HelpUpdateEntry[] | null;
    /** Tutoriales, y General para quien gestiona; null en las demás. */
    releases: HelpReleaseOption[] | null;
    tutorials: HelpTutorial[] | null;
    faq_sections: HelpFaqSection[] | null;
    suggestions: SuggestionsTabProps | null;
    upload: {
        video_max_bytes: number;
        video_chunk_bytes: number;
        attachment_max_mb: number;
    };
};
