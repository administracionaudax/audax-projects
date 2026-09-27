<?php

namespace App\Domain\Chat\Links;

/**
 * Primer enlace http(s) del cuerpo de un mensaje (markdown ligero, D-069): el de un enlace
 * [texto](https://…) o una URL suelta, lo que aparezca antes. Lo que va dentro de código (`…` o
 * bloques ```…```) no cuenta. Mismo criterio que el pintado de enlaces del frontend
 * (resources/js/components/chat/markdown.tsx).
 */
final class FirstLink
{
    public const int MAX_LENGTH = 2048;

    private const string MARKDOWN_LINK = '/\[[^\]\n]{0,500}\]\((https?:\/\/[^\s)<>]+)\)/iu';

    private const string BARE_URL = '/https?:\/\/[^\s<>"\'`]+/iu';

    public static function in(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        // Fuera el código (bloques y en línea), conservando las posiciones del resto.
        $text = (string) preg_replace_callback('/```.*?```|`[^`\n]*`/su', fn (array $match): string => str_repeat(' ', strlen($match[0])), $body);

        $candidates = [];

        if (preg_match(self::MARKDOWN_LINK, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $candidates[$match[0][1]] = $match[1][0];
        }

        if (preg_match(self::BARE_URL, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $candidates[$match[0][1]] ??= self::trimTrailing($match[0][0]);
        }

        if ($candidates === []) {
            return null;
        }

        ksort($candidates);
        $url = (string) reset($candidates);

        return strlen($url) > self::MAX_LENGTH || parse_url($url, PHP_URL_HOST) === null ? null : $url;
    }

    /**
     * Quita la puntuación final que no forma parte de la URL («mira https://a.es.») y los
     * paréntesis de cierre sin abrir («(https://a.es)»).
     */
    public static function trimTrailing(string $url): string
    {
        while ($url !== '') {
            $last = substr($url, -1);

            if (str_contains('.,;:!?\'"', $last)) {
                $url = substr($url, 0, -1);

                continue;
            }

            if ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
                $url = substr($url, 0, -1);

                continue;
            }

            break;
        }

        return $url;
    }
}
