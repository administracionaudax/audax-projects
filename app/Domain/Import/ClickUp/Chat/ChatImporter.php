<?php

namespace App\Domain\Import\ClickUp\Chat;

use App\Domain\Chat\ChannelMembership;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\EmojiCatalog;
use App\Domain\Chat\Participants;
use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\ImportRefs;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\ListName;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Domain\Import\ClickUp\SilentOutput;
use App\Domain\Tasks\AttachmentStorage;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\HourBank;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageReaction;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importación del chat de ClickUp (D-274 a D-279) desde el volcado de chat.py (ChatDump).
 *
 * Cada canal va a su sitio:
 * - de una lista con proyecto → el chat de ese proyecto (los de listas de Audax Interno, que no
 *   tienen cliente, son canales de equipo, como Marketing o Innovación),
 * - de una carpeta (cliente) → el canal del cliente,
 * - generales (del workspace, de un espacio o sueltos) → canal de equipo con su emoji,
 * - privados → grupo con sus miembros (no se abren a toda la plantilla),
 * - mensajes directos y grupos → directas y grupos del dueño del token (los únicos que la API deja
 *   leer); los directos «contigo mismo» no se importan,
 * - vacíos → no se importan; clasificacion.json manda sobre todo lo anterior.
 *
 * Los mensajes conservan fecha, autor (ChatPeople), hilo (respuesta al mensaje padre), menciones,
 * reacciones y adjuntos; el texto se pasa al Markdown del chat (ChatText). Idempotente con
 * import_refs (fuente clickup; tipos chat_channel, chat_message y chat_attachment): una segunda
 * ejecución actualiza lo importado y añade lo nuevo. Sin avisos, tiempo real ni previsualizaciones
 * (no pasa por MessageWriter) y todo lo importado queda leído. --dry-run lo hace todo en una
 * transacción que se deshace, sin copiar ficheros.
 */
final class ChatImporter
{
    public const string SOURCE = 'clickup';

    public const string AUDIT_LOG = 'import';

    public const string AUDIT_EVENT = 'clickup_chat_import';

    public const int CHUNK = 500;

    /** @var array<string, string> */
    public const array TYPES = [
        'people' => 'Personas («Usuario de ClickUp»)',
        'team' => 'Canales de equipo',
        'client' => 'Canales de cliente',
        'project' => 'Chats de proyecto',
        'group' => 'Grupos (y canales privados)',
        'direct' => 'Mensajes directos',
        'messages' => 'Mensajes',
        'replies' => 'Respuestas (hilos)',
        'mentions' => 'Menciones',
        'reactions' => 'Reacciones',
        'attachments' => 'Adjuntos',
    ];

    private ImportReport $report;

    private ImportOutput $output;

    private ImportRefs $refs;

    private ChatDump $dump;

    private ChatPeople $people;

    private bool $dryRun = false;

    public function __construct(
        private readonly ConversationDirectory $directory,
        private readonly ChannelMembership $channels,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * @throws RuntimeException si el volcado no se puede leer
     */
    public function run(string $directory, PeopleFile $peopleFile, bool $dryRun = false, bool $onlyDirect = false, ?ImportOutput $output = null): ImportReport
    {
        $started = microtime(true);
        $this->report = new ImportReport(self::TYPES);
        $this->report->dryRun = $dryRun;
        $this->dryRun = $dryRun;
        $this->output = $output ?? new SilentOutput;
        $this->dump = new ChatDump($directory);
        $this->refs = new ImportRefs(self::SOURCE);

        if ($dryRun) {
            DB::beginTransaction();
        }

        $activity = activity();
        $activity->disableLogging();

        try {
            Model::withoutEvents(function () use ($peopleFile, $onlyDirect): void {
                $this->refs->load();
                $this->people = new ChatPeople($peopleFile, $this->dump->users, $this->report);

                $channels = array_values(array_filter(
                    $this->dump->channels,
                    fn (array $channel): bool => ! $onlyDirect || in_array(ChatDump::str($channel['type'] ?? null), ['DM', 'GROUP_DM'], true),
                ));

                $this->output->stage('Canales, mensajes, respuestas, reacciones y adjuntos');
                $this->output->progressStart(count($channels));
                foreach ($channels as $channel) {
                    $this->channel($channel);
                    $this->output->progressAdvance();
                }
                $this->output->progressFinish();
            });

            $activity->enableLogging();

            if ($dryRun) {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            if ($dryRun) {
                DB::rollBack();
            }

            throw $e;
        } finally {
            $activity->enableLogging();
        }

        $this->report->seconds = microtime(true) - $started;
        $this->report->peakMemoryBytes = memory_get_peak_usage(true);

        if (! $dryRun) {
            activity(self::AUDIT_LOG)
                ->event(self::AUDIT_EVENT)
                ->withProperties([
                    'source' => self::SOURCE,
                    'counts' => $this->report->counts(),
                    'warnings' => count($this->report->warnings()),
                    'seconds' => round($this->report->seconds, 1),
                ])
                ->log('Importación del chat de ClickUp');
        }

        return $this->report;
    }

    // ---------------------------------------------------------------------------------------
    // Canales
    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $channel
     */
    private function channel(array $channel): void
    {
        $id = ChatDump::str($channel['id'] ?? null);
        $messages = $this->dump->messages($id);
        $members = $this->memberIds($id);
        [$kind, $target] = $this->classify($channel, $members);

        if ($kind === 'skip' || $messages === []) {
            if ($kind !== 'skip' && $kind !== null) {
                $this->report->count($kind, ImportReport::SKIPPED);
                $this->report->warn('Canales vacíos en ClickUp: no se importan.');
            }

            return;
        }

        $conversation = $this->conversation($id, $channel, $kind, $target, $members);

        $imported = [];
        foreach (array_chunk($messages, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $conversation, &$imported): void {
                foreach ($chunk as $message) {
                    $local = $this->message($conversation, $message);
                    if ($local !== null) {
                        $imported[ChatDump::str($message['id'] ?? null)] = $local;
                    }
                }
            });
        }

        DB::transaction(function () use ($id, $imported): void {
            foreach ($this->dump->reactions($id) as $messageId => $reactions) {
                $local = $imported[$messageId] ?? $this->refs->find('chat_message', $messageId);
                if ($local !== null) {
                    $this->reactions($local, $reactions);
                }
            }
        });

        DB::transaction(fn () => $this->participants($conversation, $kind, $members));
    }

    /**
     * Adónde va un canal: [tipo, modelo] (null si es de equipo, grupo o directo).
     *
     * @param  array<string, mixed>  $channel
     * @param  list<string>  $members  ids de ClickUp
     * @return array{0: 'team'|'client'|'project'|'group'|'direct'|'skip'|null, 1: Project|Client|null}
     */
    private function classify(array $channel, array $members): array
    {
        $id = ChatDump::str($channel['id'] ?? null);
        $type = ChatDump::str($channel['type'] ?? null);
        $override = $this->dump->overrides[$id] ?? null;

        if ($override !== null) {
            return $this->overridden($override, $id);
        }

        if ($type === 'DM') {
            if (count(array_unique($members)) < 2) {
                $this->report->warn('Mensajes directos contigo mismo (notas): no se importan.');

                return ['skip', null];
            }

            return [count(array_unique($members)) === 2 ? 'direct' : 'group', null];
        }

        if ($type === 'GROUP_DM') {
            return ['group', null];
        }

        if ($type !== 'CHANNEL') {
            $this->report->warn("Canal de un tipo desconocido ({$type}): no se importa.");

            return ['skip', null];
        }

        // Los privados no se abren a toda la plantilla: grupo con sus miembros (D-277).
        if (ChatDump::str($channel['visibility'] ?? null) === 'PRIVATE') {
            return ['group', null];
        }

        $parent = ChatDump::arr($channel['parent'] ?? null);
        $parentId = ChatDump::str($parent['id'] ?? null);

        return match ((int) ($parent['type'] ?? 0)) {
            6 => $this->listChannel($parentId, ChatDump::str($channel['name'] ?? null)),
            5 => $this->folderChannel($parentId, ChatDump::str($channel['name'] ?? null)),
            default => ['team', null],
        };
    }

    /**
     * @return array{0: 'team'|'client'|'project'|'group'|'skip', 1: Project|Client|null}
     */
    private function overridden(string $override, string $channelId): array
    {
        [$kind, $localId] = array_pad(explode(':', $override, 2), 2, null);

        return match ($kind) {
            'team', 'group', 'skip' => [$kind, null],
            'project' => ($project = Project::withTrashed()->find((int) $localId)) !== null ? ['project', $project] : $this->badOverride($channelId),
            'client' => ($client = Client::withTrashed()->find((int) $localId)) !== null ? ['client', $client] : $this->badOverride($channelId),
            default => $this->badOverride($channelId),
        };
    }

    /**
     * @return array{0: 'skip', 1: null}
     */
    private function badOverride(string $channelId): array
    {
        $this->report->warn("clasificacion.json no es válido para el canal {$channelId}: no se importa.");

        return ['skip', null];
    }

    /**
     * Canal de una lista: el chat de su proyecto (o de su bolsa); si el proyecto es interno, un
     * canal de equipo (D-275).
     *
     * @return array{0: 'team'|'project', 1: Project|null}
     */
    private function listChannel(string $listId, string $name): array
    {
        $projectId = $this->refs->find('list', $listId);

        if ($projectId === null && ($bankId = $this->refs->find('bank_list', $listId)) !== null) {
            $projectId = HourBank::query()->whereKey($bankId)->value('project_id');
        }

        $project = $projectId !== null ? Project::withTrashed()->find((int) $projectId) : null;

        if ($project === null) {
            $this->report->warn("Canales de listas sin proyecto en la app: se importan como canales de equipo ({$name}).");

            return ['team', null];
        }

        return $project->client_id === null ? ['team', null] : ['project', $project];
    }

    /**
     * Canal de una carpeta: el canal de su cliente.
     *
     * @return array{0: 'team'|'client', 1: Client|null}
     */
    private function folderChannel(string $folderId, string $name): array
    {
        $clientId = $this->refs->find('folder', $folderId);
        $client = $clientId !== null ? Client::withTrashed()->find($clientId) : null;

        if ($client === null) {
            $this->report->warn("Canales de carpetas sin cliente en la app: se importan como canales de equipo ({$name}).");

            return ['team', null];
        }

        return ['client', $client];
    }

    /**
     * La conversación local del canal (la crea la primera vez).
     *
     * @param  array<string, mixed>  $channel
     * @param  list<string>  $members
     */
    private function conversation(string $id, array $channel, string $kind, Project|Client|null $target, array $members): Conversation
    {
        $existingId = $this->refs->find('chat_channel', $id);
        $existing = $existingId !== null ? Conversation::query()->find($existingId) : null;
        $rawName = ChatDump::str($channel['name'] ?? null);
        $creator = ChatDump::str($channel['creator'] ?? null);
        $createdBy = $creator !== '' ? $this->people->user($creator)?->id : null;
        $createdAt = self::instant($channel['created_at'] ?? null) ?? now();

        $conversation = match (true) {
            $existing !== null => $existing,
            $kind === 'project' && $target instanceof Project => $this->directory->forProject($target),
            $kind === 'client' && $target instanceof Client => $this->directory->forClient($target, $createdBy),
            $kind === 'direct' => $this->direct($members, $createdBy, $createdAt),
            default => $this->newConversation($kind === 'team' ? ConversationType::Team : ConversationType::Group, $createdBy, $createdAt),
        };

        $attributes = match ($kind) {
            'team' => ['name' => self::name($rawName), 'icon' => self::icon($rawName)],
            'group' => ['name' => mb_substr($rawName !== '' ? $rawName : $this->groupName($members), 0, 120)],
            default => [],
        };
        $conversation->forceFill($attributes);
        // Una conversación que ya estaba en la app (el chat de un proyecto, una directa…) se completa.
        $outcome = match (true) {
            $existing !== null => $conversation->isDirty() ? ImportReport::UPDATED : ImportReport::UNCHANGED,
            $conversation->wasRecentlyCreated => ImportReport::CREATED,
            default => ImportReport::UPDATED,
        };
        $conversation->save();

        $this->report->count($kind, $outcome);
        $this->refs->put('chat_channel', $id, 'conversation', $conversation->id);

        return $conversation;
    }

    private function newConversation(ConversationType $type, ?int $createdBy, CarbonImmutable $createdAt): Conversation
    {
        $conversation = new Conversation(['type' => $type, 'created_by' => $createdBy]);
        $conversation->timestamps = false;
        $conversation->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $conversation->timestamps = true;

        return $conversation;
    }

    /**
     * Directa entre las dos personas (una por pareja): si ya existe en la app, se reutiliza.
     *
     * @param  list<string>  $members
     */
    private function direct(array $members, ?int $createdBy, CarbonImmutable $createdAt): Conversation
    {
        [$a, $b] = array_map(fn (string $member): int => $this->people->authorId($member), array_values(array_unique($members)));
        $key = Conversation::directKey($a, $b);

        $conversation = Conversation::query()->where('direct_key', $key)->first();
        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation(['type' => ConversationType::Direct, 'direct_key' => $key, 'created_by' => $createdBy]);
        $conversation->timestamps = false;
        $conversation->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $conversation->timestamps = true;

        return $conversation;
    }

    /**
     * Nombre de un grupo sin nombre: los nombres de pila de sus personas.
     *
     * @param  list<string>  $members
     */
    private function groupName(array $members): string
    {
        $names = [];
        foreach ($members as $member) {
            $user = $this->people->user($member);
            $names[] = explode(' ', $user->name ?? ($this->people->name($member) ?? ChatPeople::PLACEHOLDER_NAME))[0];
        }
        $names = array_values(array_unique($names));
        sort($names);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' y '.$last;
    }

    /**
     * Participantes: los de un proyecto ya los pone su chat; en los canales, sus miembros de
     * ClickUp que siguen activos y, además, a quien le toca (ChannelMembership: toda la plantilla en
     * los de equipo, los miembros de sus proyectos en los de cliente); en directas y grupos, sus
     * personas (las desactivadas, como antiguas). Lo importado queda leído.
     *
     * @param  list<string>  $members
     */
    private function participants(Conversation $conversation, string $kind, array $members): void
    {
        if ($kind !== 'project') {
            foreach (array_unique($members) as $member) {
                $user = $this->people->user($member);
                $userId = $user->id ?? ($kind === 'direct' ? $this->people->placeholder()->id : null);

                if ($userId === null || ($user !== null && ! $user->is_active && $kind !== 'direct' && $kind !== 'group')) {
                    continue;
                }

                Participants::join($conversation, $userId);

                // En los grupos, las personas desactivadas quedan como antiguas (con su histórico).
                if ($kind === 'group' && $user !== null && ! $user->is_active) {
                    $this->directory->leave($conversation, $userId);
                }
            }
        }

        if ($kind === 'team' || $kind === 'client') {
            $this->channels->syncChannel($conversation);
        }

        $last = $conversation->messages()->withTrashed()->max('id');
        $lastAt = $conversation->messages()->withTrashed()->max('created_at');

        if ($last !== null) {
            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', (int) $last))
                ->update(['last_read_message_id' => (int) $last]);
        }

        if ($lastAt !== null) {
            $conversation->forceFill(['last_message_at' => CarbonImmutable::parse((string) $lastAt)])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function memberIds(string $channelId): array
    {
        return array_values(array_filter(array_map(
            fn (array $member): string => ChatDump::str($member['id'] ?? null),
            $this->dump->members($channelId),
        ), fn (string $id): bool => $id !== ''));
    }

    // ---------------------------------------------------------------------------------------
    // Mensajes
    // ---------------------------------------------------------------------------------------

    /**
     * Crea o actualiza un mensaje. Devuelve su id local (o null si no tiene nada que importar).
     *
     * @param  array<string, mixed>  $source
     */
    private function message(Conversation $conversation, array $source): ?int
    {
        $externalId = ChatDump::str($source['id'] ?? null);
        $author = ChatDump::str($source['user_id'] ?? null);
        $authorId = $this->people->authorId($author);
        $createdAt = self::instant($source['date'] ?? null) ?? now()->toImmutable();
        $updatedAt = self::instant($source['date_updated'] ?? null);
        $parentExternal = ChatDump::str($source['parent_message'] ?? null);
        $parentId = $parentExternal !== '' ? $this->refs->find('chat_message', $parentExternal) : null;
        $isReply = $parentExternal !== '';

        $text = ChatText::convert(
            ChatDump::str($source['content'] ?? null),
            fn (string $id): ?int => $this->people->user($id)?->id,
            fn (string $id): ?string => $this->people->user($id)->name ?? $this->people->name($id),
        );

        $title = trim(ChatDump::str(ChatDump::arr($source['post_data'] ?? null)['title'] ?? null));
        $body = $title !== '' ? trim("**{$title}**\n\n".$text['body']) : $text['body'];

        // Adjuntos: los descargados se guardan; los demás quedan como enlace en el texto.
        $files = [];
        foreach ($text['attachments'] as $attachment) {
            $local = $this->dump->attachment($attachment['url']);
            $extension = $local === null ? null : mb_strtolower(pathinfo($local['name'], PATHINFO_EXTENSION));

            $tooBig = $local !== null && (int) filesize($local['file']) > AttachmentStorage::maxKilobytes() * 1024;

            if ($local !== null && ! $tooBig && $extension !== null && array_key_exists($extension, AttachmentStorage::EXTENSIONS)) {
                $files[] = [...$attachment, ...$local];
            } else {
                $body = trim($body."\n📎 [{$attachment['name']}]({$attachment['url']})");
                $this->report->warn(match (true) {
                    $local === null => 'Adjuntos sin descargar: quedan como enlace a ClickUp.',
                    $tooBig => 'Adjuntos de más del máximo de la app (max_attachment_mb): quedan como enlace a ClickUp.',
                    default => 'Adjuntos de un tipo que el chat no admite (vídeos, HEIC…): quedan como enlace a ClickUp.',
                });
            }
        }

        if ($body === '' && $files === []) {
            $this->report->count($isReply ? 'replies' : 'messages', ImportReport::SKIPPED);

            return null;
        }

        $attributes = [
            'conversation_id' => $conversation->id,
            'user_id' => $authorId,
            'type' => $body === '' ? MessageType::File : MessageType::Text,
            'body' => $body === '' ? null : $body,
            'parent_id' => $parentId,
            'edited_at' => $updatedAt !== null && $updatedAt->gt($createdAt->addSecond()) ? $updatedAt : null,
        ];

        $localId = $this->refs->find('chat_message', $externalId);
        $message = $localId !== null ? Message::withTrashed()->find($localId) : null;
        $type = $isReply ? 'replies' : 'messages';

        if ($message === null) {
            $message = new Message;
            $message->timestamps = false;
            $message->forceFill([...$attributes, 'created_at' => $createdAt, 'updated_at' => $updatedAt ?? $createdAt])->save();
            $this->report->count($type, ImportReport::CREATED);
        } else {
            // Lo que se movió en la app (otra conversación) o se borró allí, se respeta.
            unset($attributes['conversation_id']);
            $message->forceFill($attributes);
            $changed = $message->isDirty();
            $message->timestamps = false;
            $message->save();
            $this->report->count($type, $changed ? ImportReport::UPDATED : ImportReport::UNCHANGED);
            if (! $changed) {
                $this->storeFiles($message, $conversation, $files, $authorId);

                return $message->id;
            }
        }

        $this->refs->put('chat_message', $externalId, 'message', $message->id);
        $this->mentions($message, $text['mentions'], $text['everyone']);
        $this->storeFiles($message, $conversation, $files, $authorId);

        return $message->id;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function mentions(Message $message, array $userIds, bool $everyone): void
    {
        MessageMention::query()->where('message_id', $message->id)->delete();

        foreach ($userIds as $userId) {
            if ($userId !== $message->user_id) {
                MessageMention::query()->insert(['message_id' => $message->id, 'user_id' => $userId, 'everyone' => false]);
                $this->report->count('mentions', ImportReport::CREATED);
            }
        }

        if ($everyone) {
            MessageMention::query()->insert(['message_id' => $message->id, 'user_id' => null, 'everyone' => true]);
            $this->report->count('mentions', ImportReport::CREATED);
        }
    }

    /**
     * @param  list<array{url: string, name: string, file: string}>  $files
     */
    private function storeFiles(Message $message, Conversation $conversation, array $files, int $uploaderId): void
    {
        foreach ($files as $file) {
            $key = sha1($file['url'].'|'.$message->id);

            if ($this->refs->find('chat_attachment', $key) !== null) {
                $this->report->count('attachments', ImportReport::UNCHANGED);

                continue;
            }

            // En la simulación no se copia nada al disco.
            if ($this->dryRun) {
                $this->report->count('attachments', ImportReport::CREATED);

                continue;
            }

            try {
                $uploader = User::query()->findOrFail($uploaderId);
                $stored = $this->storage->store(
                    new UploadedFile($file['file'], $file['name'], null, null, true),
                    $message,
                    $conversation->type === ConversationType::Project ? $conversation->project_id : null,
                    $uploader,
                );
                $display = AttachmentStorage::cleanName($file['name']);
                if (mb_strtolower(pathinfo($display, PATHINFO_EXTENSION)) !== mb_strtolower(pathinfo($stored->path, PATHINFO_EXTENSION))) {
                    $display = $stored->original_name;
                }
                $stored->forceFill(['original_name' => $display, 'created_at' => $message->created_at, 'updated_at' => $message->created_at])->save();
                $this->refs->put('chat_attachment', $key, 'attachment', $stored->id);
                $this->report->count('attachments', ImportReport::CREATED);
            } catch (Throwable) {
                $this->report->count('attachments', ImportReport::SKIPPED);
                $this->report->warn('Adjuntos que no se han podido guardar (tipo real distinto de su extensión o ilegibles).');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $reactions
     */
    private function reactions(int $messageId, array $reactions): void
    {
        foreach ($reactions as $reaction) {
            $name = ChatDump::str($reaction['reaction'] ?? $reaction['emoji'] ?? null);
            $emoji = ReactionEmoji::of($name);

            if ($emoji === null) {
                $this->report->count('reactions', ImportReport::SKIPPED);
                $this->report->warn("Reacciones que el chat no tiene: «{$name}».");

                continue;
            }

            $inserted = MessageReaction::query()->insertOrIgnore([
                'message_id' => $messageId,
                'user_id' => $this->people->authorId(ChatDump::str($reaction['user_id'] ?? null)),
                'emoji' => $emoji,
                'created_at' => self::instant($reaction['date'] ?? null) ?? now(),
            ]);
            $this->report->count('reactions', $inserted > 0 ? ImportReport::CREATED : ImportReport::UNCHANGED);
        }
    }

    // ---------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------

    /**
     * Nombre de un canal de equipo: sin emojis (van aparte, en icon).
     */
    public static function name(string $raw): string
    {
        $name = ListName::stripEmoji($raw);

        return mb_substr($name !== '' ? $name : 'Canal de ClickUp', 0, 120);
    }

    /**
     * El primer emoji del nombre, si es uno del selector del chat.
     */
    public static function icon(string $raw): ?string
    {
        if (preg_match('/^\X/u', trim($raw), $m) !== 1) {
            return null;
        }

        return mb_strlen($m[0]) <= 16 && EmojiCatalog::contains($m[0]) ? $m[0] : null;
    }

    private static function instant(mixed $milliseconds): ?CarbonImmutable
    {
        if (! is_numeric($milliseconds) || (int) $milliseconds <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $milliseconds, 'UTC');
    }
}
