<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Texto enriquecido de descripciones y comentarios (editor Tiptap). El HTML se sanea SIEMPRE en el
 * servidor antes de guardarlo: solo formato básico, enlaces http(s)/mailto y menciones
 * (<span data-type="mention" data-id="7" data-label="Ana">). Nada de estilos, scripts ni imágenes.
 */
final class RichText
{
    public const int MAX_LENGTH = 50_000;

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = trim(self::sanitizer()->sanitize($html));

        return self::isBlank($clean) ? null : $clean;
    }

    /**
     * Ids de los usuarios mencionados (sin repetir).
     *
     * @return list<int>
     */
    public static function mentionedUserIds(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all('/<span\b[^>]*\bdata-type="mention"[^>]*>/i', $html, $spans);

        $ids = [];
        foreach ($spans[0] as $span) {
            if (preg_match('/\bdata-id="(\d+)"/', $span, $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Texto plano (búsqueda, notificaciones, vistas previas).
     */
    public static function toPlainText(?string $html, int $limit = 0): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $limit > 0 ? mb_strimwidth($text, 0, $limit, '…') : $text;
    }

    public static function isBlank(?string $html): bool
    {
        return self::toPlainText($html) === '' && ! str_contains((string) $html, 'data-type="mention"');
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('strong')
                ->allowElement('em')
                ->allowElement('u')
                ->allowElement('s')
                ->allowElement('code')
                ->allowElement('pre')
                ->allowElement('blockquote')
                ->allowElement('ul')
                ->allowElement('ol')
                ->allowElement('li')
                ->allowElement('h3')
                ->allowElement('h4')
                ->allowElement('hr')
                ->allowElement('a', ['href'])
                ->allowElement('span', ['data-type', 'data-id', 'data-label'])
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks(false)
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(self::MAX_LENGTH)
        );
    }
}
