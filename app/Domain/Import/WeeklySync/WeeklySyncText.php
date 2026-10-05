<?php

namespace App\Domain\Import\WeeklySync;

use App\Support\RichText;
use Illuminate\Support\Str;

/**
 * Textos de WeeklySync al formato de Audax: Markdown (o HTML) → HTML saneado con RichText, y las
 * menciones `@[Nombre](user:uuid)` → `<span data-type="mention" data-id="…">` con el id de Audax
 * (o el nombre en texto si la persona se queda fuera).
 */
final class WeeklySyncText
{
    /**
     * @param  callable(string): ?int  $user  persona de WeeklySync → cuenta de Audax
     * @param  callable(int): ?string  $name  cuenta de Audax → nombre
     */
    public static function rich(?string $text, ?callable $user = null, ?callable $name = null): ?string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));

        if ($text === '') {
            return null;
        }

        $mentions = [];
        $text = (string) preg_replace_callback(
            '/@\[([^\]\n]{1,120})\]\(user:([0-9a-fA-F-]{36})\)/',
            function (array $match) use (&$mentions, $user, $name): string {
                $label = trim($match[1]);
                $local = $user !== null ? $user(strtolower($match[2])) : null;

                if ($local === null) {
                    return '@'.$label;
                }

                $label = ($name !== null ? $name($local) : null) ?? $label;
                $token = 'WSMENTION'.count($mentions).'X';
                $mentions[$token] = sprintf('<span data-type="mention" data-id="%d" data-label="%s">@%s</span>', $local, e($label), e($label));

                return $token;
            },
            $text,
        );

        $html = self::looksLikeHtml($text)
            ? $text
            : Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $html = strtr($html, $mentions);

        return RichText::sanitize(Str::limit($html, RichText::MAX_LENGTH, ''));
    }

    /**
     * Texto plano de una sola línea o párrafo (títulos, resúmenes de versión, descripciones).
     */
    public static function plain(mixed $text, int $limit = 0): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", is_scalar($text) ? (string) $text : ''));

        return $limit > 0 ? Str::limit($text, $limit, '') : $text;
    }

    private static function looksLikeHtml(string $text): bool
    {
        return preg_match('#^\s*<(p|div|h[1-6]|ul|ol|blockquote|pre|table|br)\b#i', $text) === 1;
    }
}
