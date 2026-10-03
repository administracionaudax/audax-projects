<?php

namespace App\Search\Sources;

use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Http\Resources\Chat\ChatUsers;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Normalizer;

/**
 * Mensajes del chat (SPEC §3 y §12): el texto de los mensajes, los nombres de sus archivos y las
 * transcripciones de sus audios, SOLO de las conversaciones que quien busca puede ver (D-071, igual
 * que ConversationPolicy::view: las suyas y, si es admin, todas las de proyecto y de grupo; nunca
 * las directas ajenas). Sin mensajes borrados, ocultos ni de sistema.
 * - Sin mayúsculas y, en PostgreSQL, sin tildes (MatchesText).
 * - Acotada: una consulta con LIMIT, lo más reciente primero (índice por id), más otra para los
 *   nombres de las menciones solo si algún resultado las tiene.
 * - El fragmento con contexto se recorta aquí (sin tildes ni mayúsculas, como la consulta); el
 *   navegador resalta la palabra (resources/js/components/chat/media/text-match.tsx).
 * La usan la búsqueda global (Ctrl+K) y la página /chat/buscar (ChatSearchController).
 */
class MessageSource implements SearchSource
{
    use MatchesText;

    public const string KIND_MESSAGE = 'message';

    public const string KIND_FILE = 'file';

    public const string KIND_TRANSCRIPTION = 'transcription';

    /**
     * Resultados de la búsqueda global: el fragmento (o el nombre del archivo) y, debajo, la
     * conversación, quién lo escribió y cuándo. Lleva a la conversación, en ese mensaje.
     */
    public function search(User $user, string $query, int $limit): array
    {
        return array_map(fn (array $hit): SearchResult => new SearchResult(
            type: 'message',
            id: $hit['id'],
            title: $hit['match'] === self::KIND_FILE
                ? self::text('chat_media.search.file', ['name' => (string) $hit['file_name']])
                : $hit['excerpt'],
            subtitle: implode(' · ', array_filter([
                $hit['match'] === self::KIND_TRANSCRIPTION ? self::text('chat_media.search.audio') : null,
                $hit['conversation']['label'],
                $hit['author'],
                $hit['created_at'] === null ? null : CarbonImmutable::parse($hit['created_at'])->setTimezone(LocalTime::timezone())->format('d/m/Y'),
            ])),
            url: $hit['url'],
        ), $this->find($user, $query, $limit, radius: 45));
    }

    /**
     * @param  string|null  $kind  solo mensajes, archivos o transcripciones (KIND_*)
     * @param  int|null  $beforeId  para seguir: solo mensajes anteriores a ese id
     * @return list<array{id: int, conversation_id: int, conversation: array{type: string, label: string, code: string|null}, author: string|null, created_at: string|null, match: string, excerpt: string, file_name: string|null, is_audio: bool, url: string}>
     */
    public function find(User $user, string $text, int $limit, ?int $conversationId = null, ?string $kind = null, ?int $beforeId = null, int $radius = 80): array
    {
        $text = trim($text);

        if ($text === '' || $limit < 1 || ! $user->isInternal() || ! $user->is_active) {
            return [];
        }

        $morph = (new Message)->getMorphClass();

        // Mensajes con un archivo o una transcripción que coincide (subconsultas sin correlación: el
        // motor las resuelve una vez).
        $files = Attachment::query()->select('attachable_id')->where('attachable_type', $morph);
        $this->whereMatches($files, ['original_name'], $text);

        $transcripts = AudioTranscription::query()->select('message_id')->where('status', TranscriptionStatus::Done->value);
        $this->whereMatches($transcripts, ['text'], $text);

        // Para cada resultado: el archivo que coincide, el texto del audio y, en las directas, la otra persona.
        $matchedFile = Attachment::query()->select('original_name')
            ->whereColumn('attachable_id', 'messages.id')
            ->where('attachable_type', $morph)
            ->orderBy('id')
            ->limit(1);
        $this->whereMatches($matchedFile, ['original_name'], $text);

        $transcript = AudioTranscription::query()->select('text')
            ->whereColumn('message_id', 'messages.id')
            ->where('status', TranscriptionStatus::Done->value)
            ->limit(1);

        $peer = ConversationParticipant::query()
            ->join('users as peers', 'peers.id', '=', 'conversation_participants.user_id')
            ->select('peers.name')
            ->whereColumn('conversation_participants.conversation_id', 'messages.conversation_id')
            ->where('conversation_participants.user_id', '!=', $user->id)
            ->orderBy('conversation_participants.id')
            ->limit(1);

        $rows = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->leftJoin('projects', 'projects.id', '=', 'conversations.project_id')
            ->leftJoin('users as authors', 'authors.id', '=', 'messages.user_id')
            ->whereNull('messages.hidden_at')
            ->where('messages.type', '!=', MessageType::System->value)
            ->where(fn (Builder $visible) => $this->whereVisible($visible, $user))
            ->where(function (Builder $match) use ($kind, $text, $files, $transcripts): void {
                if ($kind === null || $kind === self::KIND_MESSAGE) {
                    $this->whereMatches($match, ['messages.body'], $text);
                }
                if ($kind === null || $kind === self::KIND_FILE) {
                    $match->orWhereIn('messages.id', $files);
                }
                if ($kind === null || $kind === self::KIND_TRANSCRIPTION) {
                    $match->orWhereIn('messages.id', $transcripts);
                }
            })
            ->when($conversationId !== null, fn (Builder $query) => $query->where('messages.conversation_id', $conversationId))
            ->when($beforeId !== null, fn (Builder $query) => $query->where('messages.id', '<', $beforeId))
            ->select([
                'messages.id', 'messages.conversation_id', 'messages.user_id', 'messages.type', 'messages.body', 'messages.created_at',
                'conversations.type as conversation_type', 'conversations.name as conversation_name',
                'projects.name as project_name', 'projects.code as project_code',
                'authors.name as author_name',
            ])
            ->selectSub($matchedFile, 'matched_file')
            ->selectSub($transcript, 'transcript')
            ->selectSub($peer, 'peer_name')
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->get();

        $names = $this->mentionNames($rows);

        return array_values($rows->map(fn (Message $row): array => $this->hit($row, $text, $kind, $names, $radius))->all());
    }

    /**
     * De entre $conversationIds, las que $user puede ver, en UNA consulta y con la misma regla que
     * ConversationPolicy::view (participante activo; el admin, además, las que no son directas).
     * Para autorizar listas sin una consulta por conversación.
     *
     * @param  list<int>  $conversationIds
     * @return list<int>
     */
    public static function visibleConversationIds(User $user, array $conversationIds): array
    {
        if ($conversationIds === [] || ! $user->isInternal() || ! $user->is_active) {
            return [];
        }

        return array_values(Conversation::query()
            ->whereKey($conversationIds)
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('participants', fn (Builder $participants) => $participants
                    ->where('user_id', $user->id)
                    ->whereNull('left_at'));

                if ($user->hasRole('admin')) {
                    $query->orWhere('type', '!=', ConversationType::Direct->value);
                }
            })
            // Colaborador externo (D-134): solo las conversaciones de los proyectos que ve.
            ->when($user->visibleProjectIds() !== null, fn (Builder $query) => $query
                ->where('type', ConversationType::Project->value)
                ->whereIn('project_id', $user->visibleProjectIds() ?? []))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Nombre de una conversación para quien la mira: el proyecto, el grupo o la otra persona.
     */
    public static function conversationLabel(Conversation $conversation, User $viewer): string
    {
        $label = match ($conversation->type) {
            ConversationType::Project => $conversation->project()->withTrashed()->value('name'),
            ConversationType::Group => $conversation->name,
            ConversationType::Direct => $conversation->participants()
                ->join('users', 'users.id', '=', 'conversation_participants.user_id')
                ->where('conversation_participants.user_id', '!=', $viewer->id)
                ->value('users.name'),
        };

        return is_string($label) && $label !== '' ? $label : self::text('chat_media.search.untitled');
    }

    /**
     * Fragmento de $text alrededor de la primera aparición de $needle (sin tildes ni mayúsculas),
     * con unos $radius caracteres de contexto a cada lado, cortado por palabras y con «…».
     */
    public static function excerpt(string $text, string $needle, int $radius = 80): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $chars = mb_str_split($text);
        $length = count($chars);
        [$folded, $map] = self::fold($text);
        $needle = self::fold(trim($needle))[0];
        $position = $needle === '' ? false : mb_strpos($folded, $needle);

        if ($position === false) {
            return $length > 2 * $radius ? rtrim(implode('', array_slice($chars, 0, 2 * $radius))).'…' : $text;
        }

        $start = $map[$position];
        $end = $map[$position + mb_strlen($needle) - 1] + 1;
        $from = max(0, $start - $radius);
        $to = min($length, $end + $radius);

        // Sin cortar palabras: se avanza el inicio hasta el siguiente espacio y se retrocede el final
        // hasta el anterior (como mucho 15 caracteres, para no comerse la coincidencia).
        if ($from > 0) {
            $space = array_search(' ', array_slice($chars, $from, min(15, $start - $from)), true);
            $from = $space === false ? $from : $from + (int) $space + 1;
        }
        if ($to < $length) {
            $window = array_slice($chars, max($end, $to - 15), $to - max($end, $to - 15));
            $space = array_search(' ', array_reverse($window, true), true);
            $to = $space === false ? $to : (int) $space + max($end, $to - 15);
        }

        return ($from > 0 ? '…' : '').trim(implode('', array_slice($chars, $from, $to - $from))).($to < $length ? '…' : '');
    }

    /**
     * ¿Contiene $text a $needle, sin tildes ni mayúsculas?
     */
    public static function contains(string $text, string $needle): bool
    {
        $needle = self::fold(trim($needle))[0];

        return $needle !== '' && str_contains(self::fold($text)[0], $needle);
    }

    /**
     * Mismo alcance que ConversationPolicy::view.
     *
     * @param  Builder<Message>  $query
     */
    private function whereVisible(Builder $query, User $user): void
    {
        $query->whereIn('messages.conversation_id', ConversationParticipant::query()
            ->select('conversation_id')
            ->where('user_id', $user->id)
            ->whereNull('left_at'));

        if ($user->hasRole('admin')) {
            $query->orWhere('conversations.type', '!=', ConversationType::Direct->value);
        }

        // Colaborador externo (D-134): solo las conversaciones de los proyectos que ve.
        $projectIds = $user->visibleProjectIds();
        if ($projectIds !== null) {
            $query->where('conversations.type', ConversationType::Project->value)
                ->whereIn('conversations.project_id', $projectIds);
        }
    }

    /**
     * @param  array<int, array<int, string>>  $names  por mensaje (mentionNames)
     * @return array{id: int, conversation_id: int, conversation: array{type: string, label: string, code: string|null}, author: string|null, created_at: string|null, match: string, excerpt: string, file_name: string|null, is_audio: bool, url: string}
     */
    private function hit(Message $row, string $text, ?string $kind, array $names, int $radius): array
    {
        $body = self::plain((string) $row->body, $names[$row->id] ?? []);
        $file = self::attribute($row, 'matched_file');
        $transcript = self::attribute($row, 'transcript');

        $match = match (true) {
            $kind !== null => $kind,
            $body !== '' && self::contains($body, $text) => self::KIND_MESSAGE,
            $file !== null => self::KIND_FILE,
            $transcript !== null && self::contains($transcript, $text) => self::KIND_TRANSCRIPTION,
            default => $body === '' && $transcript !== null ? self::KIND_TRANSCRIPTION : self::KIND_MESSAGE,
        };

        $excerpt = match ($match) {
            self::KIND_FILE => (string) $file,
            self::KIND_TRANSCRIPTION => self::excerpt((string) $transcript, $text, $radius),
            default => self::excerpt($body, $text, $radius),
        };

        $type = (string) self::attribute($row, 'conversation_type');
        $label = match ($type) {
            ConversationType::Project->value => self::attribute($row, 'project_name'),
            ConversationType::Group->value => self::attribute($row, 'conversation_name'),
            default => self::attribute($row, 'peer_name'),
        };

        return [
            'id' => $row->id,
            'conversation_id' => $row->conversation_id,
            'conversation' => [
                'type' => $type,
                'label' => $label ?? self::text($type === ConversationType::Direct->value ? 'chat_media.search.direct' : 'chat_media.search.untitled'),
                'code' => $type === ConversationType::Project->value ? self::attribute($row, 'project_code') : null,
            ],
            'author' => self::attribute($row, 'author_name'),
            'created_at' => $row->created_at?->toIso8601ZuluString(),
            'match' => $match,
            'excerpt' => $excerpt,
            'file_name' => $file,
            'is_audio' => $row->type === MessageType::Audio,
            'url' => route('chat.show', ['conversation' => $row->conversation_id, 'mensaje' => $row->id], false),
        ];
    }

    /**
     * Nombres de las personas mencionadas (<@ID>) en cada resultado, solo las que se resuelven
     * (ChatUsers::mentionable: participantes de la conversación o filas de message_mentions). Dos
     * consultas, solo si hay alguna.
     *
     * @param  Collection<int, Message>  $rows
     * @return array<int, array<int, string>> id del mensaje => id → nombre
     */
    private function mentionNames(Collection $rows): array
    {
        $allowed = ChatUsers::mentionable($rows);

        if ($allowed === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = User::query()->whereKey(array_values(array_unique(array_merge(...array_values($allowed)))))->pluck('name', 'id')->all();

        return array_map(fn (array $ids): array => array_intersect_key($names, array_flip($ids)), $allowed);
    }

    /**
     * Cuerpo en texto plano para el fragmento: menciones con su nombre y sin marcas de markdown.
     *
     * @param  array<int, string>  $names
     */
    private static function plain(string $body, array $names): string
    {
        $text = (string) preg_replace_callback('/<@(\d{1,10})>/', fn (array $match): string => '@'.($names[(int) $match[1]] ?? __('conversations.unknown_mention')), $body);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)\s]*\)/u', '$1', $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Texto plegado (sin tildes y en minúsculas) y, por cada carácter plegado, su posición en el
     * original, para recortar el fragmento sobre el texto original.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function fold(string $text): array
    {
        $folded = '';
        $map = [];

        foreach (mb_str_split($text) as $index => $char) {
            foreach (mb_str_split(self::foldChar($char)) as $piece) {
                $folded .= $piece;
                $map[] = $index;
            }
        }

        return [$folded, $map];
    }

    private static function foldChar(string $char): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];

        if (isset($cache[$char])) {
            return $cache[$char];
        }

        $base = $char;

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($char, Normalizer::FORM_D);
            $base = is_string($decomposed) ? (string) preg_replace('/\p{Mn}+/u', '', $decomposed) : $char;
        }

        return $cache[$char] = mb_strtolower($base === '' ? $char : $base);
    }

    private static function attribute(Message $row, string $key): ?string
    {
        $value = $row->getAttribute($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
