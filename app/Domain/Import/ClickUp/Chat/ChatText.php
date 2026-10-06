<?php

namespace App\Domain\Import\ClickUp\Chat;

use App\Domain\Chat\MessageWriter;

/**
 * Texto de un mensaje del chat de ClickUp (Markdown de su API, `content_format=text/md`) al
 * Markdown ligero del chat de la app (D-069, D-276):
 *
 * - menciones `[@Ana](#user_mention#123)` → `<@id local>` (o `@Ana` si la persona no se importa),
 *   y `[@followers](#…followers_tag)` o `@channel`/`@here` → `@todos`,
 * - imágenes y ficheros de clickup-attachments (`![nombre](url)` o `[nombre](url)`) → adjuntos del
 *   mensaje (se quitan del texto); otros enlaces se quedan,
 * - enlaces con el texto partido en varias líneas (las tarjetas de Loom, p. ej.) → la URL sola,
 * - listas de tareas `- [x]`/`- [ ]` → ☑/☐, viñetas → «• », títulos `#` → **negrita**,
 * - escapes de Markdown (`\-`, `\*`…) → el carácter, y como mucho 10.000 caracteres.
 *
 * Lo que el chat no pinta (citas, tablas) queda como texto: nada se pierde.
 */
final class ChatText
{
    /** Ficheros de ClickUp (públicos por su URL, los descarga el volcado). */
    public const string ATTACHMENT_HOST = '/^https:\/\/[a-z0-9.-]*clickup-attachments\.com\//i';

    /**
     * @param  callable(string): ?int  $userFor  id de ClickUp → id local (null si no se importa)
     * @param  callable(string): ?string  $nameFor  id de ClickUp → nombre que se conoce
     * @return array{body: string, mentions: list<int>, everyone: bool, attachments: list<array{url: string, name: string}>}
     */
    public static function convert(string $content, callable $userFor, callable $nameFor): array
    {
        $mentions = [];
        $everyone = false;
        $attachments = [];
        $text = str_replace(["\r\n", "\r"], "\n", $content);

        // Imágenes y ficheros adjuntos (antes que los enlaces normales).
        $text = (string) preg_replace_callback(
            '/!?\[([^\]\n]{0,300})\]\((https:\/\/[^)\s]+)\)/u',
            function (array $m) use (&$attachments): string {
                if (preg_match(self::ATTACHMENT_HOST, $m[2]) !== 1) {
                    return $m[0];
                }
                $name = trim($m[1]) !== '' ? trim($m[1]) : rawurldecode(basename((string) parse_url($m[2], PHP_URL_PATH)));
                $attachments[] = ['url' => $m[2], 'name' => $name];

                return '';
            },
            $text,
        );

        // Menciones de personas y de grupos.
        $text = (string) preg_replace_callback(
            '/\[@([^\]\n]{0,120})\]\(#([a-z_]*mention)#([^)\s]*)\)/u',
            function (array $m) use ($userFor, $nameFor, &$mentions, &$everyone): string {
                if ($m[2] !== 'user_mention') {
                    $everyone = true;

                    return '@todos';
                }
                $local = $userFor($m[3]);
                if ($local !== null) {
                    $mentions[] = $local;

                    return "<@{$local}>";
                }

                return '@'.($nameFor($m[3]) ?? trim($m[1]));
            },
            $text,
        );

        // Otros anclajes internos de ClickUp (#task_mention…): su texto.
        $text = (string) preg_replace('/\[([^\]\n]{1,300})\]\(#[^)\s]*\)/u', '$1', $text);

        // Enlaces cuyo texto ocupa varias líneas: la URL sola.
        $text = (string) preg_replace_callback(
            '/\[((?:[^\[\]]|\n){0,600}?)\]\((https?:\/\/[^)\s]+)\)/u',
            fn (array $m): string => str_contains($m[1], "\n") ? $m[2] : $m[0],
            $text,
        );

        if (preg_match('/(^|\s)@(channel|here|everyone|todos)\b/u', $text) === 1) {
            $everyone = true;
            $text = (string) preg_replace('/(^|\s)@(channel|here|everyone)\b/u', '$1@todos', $text);
        }

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = self::line($line);
        }
        $text = implode("\n", $lines);

        // Escapes de Markdown y espacios de sobra.
        $text = (string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!>~|])/u', '$1', $text);
        $text = (string) preg_replace("/[ \t]+\n/u", "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", $text));

        if (mb_strlen($text) > MessageWriter::MAX_BODY) {
            $text = mb_substr($text, 0, MessageWriter::MAX_BODY - 1).'…';
        }

        return [
            'body' => $text,
            'mentions' => array_values(array_unique($mentions)),
            'everyone' => $everyone,
            'attachments' => $attachments,
        ];
    }

    private static function line(string $line): string
    {
        // Tareas: «- [x] hecho» y «- [ ] pendiente».
        if (preg_match('/^(\s*)[-*+]\s+\[([ xX])\]\s?(.*)$/u', $line, $m) === 1) {
            return $m[1].(trim($m[2]) === '' ? '☐ ' : '☑ ').$m[3];
        }

        // Viñetas: «- », «* » o «+ » (ClickUp sangra con «*   »).
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/u', $line, $m) === 1 && $m[2] !== '') {
            return $m[1].'• '.$m[2];
        }

        // Títulos: en negrita.
        if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
            return '**'.$m[1].'**';
        }

        return $line;
    }
}
