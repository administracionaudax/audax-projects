<?php

namespace App\Domain\Import\ClickUp\Chat;

use JsonException;
use RuntimeException;

/**
 * Volcado del chat de ClickUp (scratchpad/clickup-chat/chat.py, D-274) en una carpeta:
 *
 *   meta.json                  workspace y dueño del token
 *   channels.json              canales (con parent = ubicación) · obligatorio
 *   members/<canal>.json       {"members": [...]} miembros de cada canal
 *   messages/<canal>.json      {"messages": [...], "done": bool}
 *   replies/<mensaje>.json     {"replies": [...]} respuestas (hilos)
 *   reactions/<canal>.json     {"<mensaje>": [{"reaction", "user_id", "date"}]}
 *   attachments.json           {"<url>": {"path", "name", "size", "type"} | {"error"}}
 *   attachments/…              los ficheros
 *   users.json                 {"<id>": {"name", "email"}} (opcional)
 *   clasificacion.json         {"<canal>": "team"|"group"|"skip"|"project:<id>"|"client:<id>"} (opcional)
 *
 * Lo que falta (las reacciones, si la descarga aún no ha llegado) se trata como vacío.
 */
final class ChatDump
{
    /** @var array<string, mixed> */
    public readonly array $meta;

    /** @var list<array<string, mixed>> */
    public readonly array $channels;

    /** @var array<string, array{name?: string|null, email?: string|null}> */
    public readonly array $users;

    /** @var array<string, string> */
    public readonly array $overrides;

    /** @var array<string, array<string, mixed>> */
    private array $attachments;

    public function __construct(public readonly string $directory)
    {
        if (! is_file($this->path('channels.json'))) {
            throw new RuntimeException("Falta el fichero {$this->path('channels.json')}.");
        }

        $this->meta = self::arr($this->json('meta.json', []));
        $this->channels = array_values(array_filter(self::arr($this->json('channels.json', [])), 'is_array'));
        $this->overrides = array_map('strval', self::arr($this->json('clasificacion.json', [])));
        $this->attachments = self::arr($this->json('attachments.json', []));

        $users = [];
        foreach (self::arr($this->json('users.json', [])) as $id => $user) {
            if (is_array($user)) {
                $users[(string) $id] = ['name' => self::str($user['name'] ?? null), 'email' => self::str($user['email'] ?? null)];
            }
        }
        foreach ($this->channels as $channel) {
            foreach ($this->members(self::str($channel['id'] ?? null)) as $member) {
                $users[self::str($member['id'] ?? null)] = ['name' => self::str($member['name'] ?? $member['username'] ?? null), 'email' => self::str($member['email'] ?? null)];
            }
        }
        $this->users = $users;
    }

    public function tokenOwner(): string
    {
        return self::str(self::arr($this->meta['token_owner'] ?? null)['id'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(string $channelId): array
    {
        return array_values(array_filter(self::arr(self::arr($this->json("members/{$channelId}.json", []))['members'] ?? null), 'is_array'));
    }

    /**
     * Mensajes del canal y, detrás de cada uno con respuestas, sus respuestas (con parent_message).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(string $channelId): array
    {
        $out = [];
        foreach (self::arr(self::arr($this->json("messages/{$channelId}.json", []))['messages'] ?? null) as $message) {
            if (! is_array($message)) {
                continue;
            }
            $out[] = $message;

            if ((int) ($message['replies_count'] ?? 0) > 0) {
                foreach (self::arr(self::arr($this->json('replies/'.self::str($message['id'] ?? null).'.json', []))['replies'] ?? null) as $reply) {
                    if (is_array($reply)) {
                        $reply['parent_message'] ??= $message['id'] ?? null;
                        $out[] = $reply;
                    }
                }
            }
        }

        usort($out, fn (array $a, array $b): int => [(int) ($a['date'] ?? 0), self::str($a['id'] ?? null)] <=> [(int) ($b['date'] ?? 0), self::str($b['id'] ?? null)]);

        return $out;
    }

    /**
     * @return array<string, list<array<string, mixed>>> mensaje → reacciones
     */
    public function reactions(string $channelId): array
    {
        $out = [];
        foreach (self::arr($this->json("reactions/{$channelId}.json", [])) as $messageId => $list) {
            if (is_array($list) && array_is_list($list)) {
                $out[(string) $messageId] = array_values(array_filter($list, 'is_array'));
            }
        }

        return $out;
    }

    /**
     * Fichero local de un adjunto descargado, o null si no se descargó.
     *
     * @return array{file: string, name: string}|null
     */
    public function attachment(string $url): ?array
    {
        $entry = self::arr($this->attachments[$url] ?? null);
        $relative = self::str($entry['path'] ?? null);

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $file = $this->path($relative);

        return is_file($file) ? ['file' => $file, 'name' => self::str($entry['name'] ?? null) ?: basename($file)] : null;
    }

    private function path(string $relative): string
    {
        return rtrim($this->directory, '/').'/'.$relative;
    }

    private function json(string $relative, mixed $default): mixed
    {
        $file = $this->path($relative);

        if (! is_file($file)) {
            return $default;
        }

        try {
            return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("El fichero {$file} no es JSON válido: {$e->getMessage()}");
        }
    }

    /**
     * @return array<mixed>
     */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
