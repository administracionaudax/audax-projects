<?php

namespace App\Http\Resources\Chat;

use App\Domain\Chat\Links\FirstLink;
use App\Models\User;

/**
 * Cuerpo de un mensaje (markdown ligero) convertido en HTML sencillo para la descripción de la
 * tarea que se crea desde él: párrafos, saltos de línea, negrita, cursiva, código y enlaces
 * http(s). Todo el texto se escapa y las menciones quedan como texto (@Nombre: no avisan otra
 * vez). TaskWriter lo vuelve a sanear con App\Support\RichText antes de guardarlo.
 */
final class MessageHtml
{
    /**
     * @param  array<int, User>  $users  personas mencionadas
     */
    public static function from(string $body, array $users): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($body)) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $lines = array_map(fn (string $line): string => self::inline($line, $users), preg_split('/\R/u', trim($paragraph)) ?: []);
            $html .= '<p>'.implode('<br>', $lines).'</p>';
        }

        return $html;
    }

    /**
     * @param  array<int, User>  $users
     */
    private static function inline(string $text, array $users): string
    {
        $slots = [];
        $slot = function (string $html) use (&$slots): string {
            $key = "\u{E000}".count($slots)."\u{E001}";
            $slots[$key] = $html;

            return $key;
        };

        $text = (string) preg_replace_callback('/`([^`\n]+)`/u', fn (array $m): string => $slot('<code>'.e($m[1]).'</code>'), $text);
        $text = (string) preg_replace_callback('/\[([^\]\n]{1,500})\]\((https?:\/\/[^\s)<>]+)\)/iu', fn (array $m): string => $slot('<a href="'.e($m[2]).'">'.e($m[1]).'</a>'), $text);
        $text = (string) preg_replace_callback('/https?:\/\/[^\s<>"\'`\x{E000}\x{E001}]+/iu', function (array $m) use ($slot): string {
            $url = FirstLink::trimTrailing($m[0]);

            return $slot('<a href="'.e($url).'">'.e($url).'</a>').e(substr($m[0], strlen($url)));
        }, $text);
        $text = (string) preg_replace_callback('/<@(\d{1,10})>/', fn (array $m): string => $slot(e('@'.($users[(int) $m[1]]->name ?? __('conversations.unknown_person')))), $text);

        $text = e($text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}*])\*\*(?=\S)(.+?)(?<=\S)\*\*(?![\p{L}\p{N}*])/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)(.+?)(?<=\S)\*(?![\p{L}\p{N}*])/u', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $text);

        return strtr($text, $slots);
    }
}
