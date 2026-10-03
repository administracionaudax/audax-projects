<?php

namespace App\Http\Resources\Chat;

use App\Domain\Chat\Mentions;
use App\Models\User;

/**
 * Texto plano de un mensaje para la lista de conversaciones, las citas de un hilo y la barra de
 * fijados: sin marcas de markdown, con las menciones <@ID> como @Nombre y recortado. El pintado
 * completo (con enlaces y formato) lo hace el frontend (components/chat/markdown.tsx).
 */
final class MessagePreview
{
    public const int LENGTH = 140;

    /**
     * @param  array<int, User>  $users  personas mencionadas (ChatUsers::load)
     */
    public static function plain(?string $body, array $users, int $limit = self::LENGTH): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $text = (string) preg_replace('/```[^\n]*\n?(.*?)```/su', '$1', $body);
        $text = (string) preg_replace('/`([^`\n]*)`/u', '$1', $text);
        $text = (string) preg_replace('/\[([^\]\n]{0,500})\]\((?:https?:\/\/|mailto:)[^\s)]+\)/iu', '$1', $text);
        $text = (string) preg_replace_callback('/<@(\d{1,10})>/', function (array $match) use ($users): string {
            $user = $users[(int) $match[1]] ?? null;

            return '@'.($user !== null ? $user->name : __('conversations.unknown_mention'));
        }, $text);
        // Marcas de énfasis al principio o al final de una palabra (no las de dentro: snake_case).
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])(\*\*|\*|__|_)(?=\S)|(?<=\S)(\*\*|\*|__|_)(?![\p{L}\p{N}])/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }

    /**
     * @return list<int>
     */
    public static function mentionIds(?string $body): array
    {
        return $body === null || $body === '' ? [] : Mentions::parse($body)['users'];
    }
}
