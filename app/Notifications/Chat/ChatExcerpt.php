<?php

namespace App\Notifications\Chat;

use App\Enums\MessageType;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Texto plano y corto de un mensaje para los avisos (campana y navegador): las menciones <@ID>
 * como @Nombre, sin las marcas del markdown ligero y con 140 caracteres como mucho. Nunca HTML.
 */
final class ChatExcerpt
{
    public const int LENGTH = 140;

    /**
     * @param  array<int, string>  $names  id → nombre de las personas mencionadas
     */
    public static function of(Message $message, array $names = []): ?string
    {
        $text = $message->body === null ? '' : self::plain($message->body, $names);

        if ($text !== '') {
            return $text;
        }

        if ($message->type === MessageType::Audio) {
            return __('realtime.excerpt.audio');
        }

        if ($message->type === MessageType::File) {
            return __('realtime.excerpt.file');
        }

        return null;
    }

    /**
     * @param  array<int, string>  $names
     */
    public static function plain(string $body, array $names = []): string
    {
        $text = (string) preg_replace_callback(
            '/<@(\d{1,10})>/',
            fn (array $match): string => '@'.($names[(int) $match[1]] ?? __('realtime.excerpt.someone')),
            $body,
        );

        // [texto](url) → texto; bloques de código, negrita, cursiva, tachado y código en línea.
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)\s]*\)/u', '$1', $text);
        $text = (string) preg_replace('/```[A-Za-z0-9_-]*/u', ' ', $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])[*_](?=\S)|(?<=\S)[*_](?![\p{L}\p{N}])/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, self::LENGTH, '…');
    }
}
