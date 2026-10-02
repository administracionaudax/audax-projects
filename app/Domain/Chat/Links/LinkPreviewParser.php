<?php

namespace App\Domain\Chat\Links;

use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * Título y descripción de una página HTML (D-069): og:title, twitter:title o <title>, y
 * og:description, twitter:description o la meta description. Parser HTML5 de PHP 8.4
 * (Dom\HTMLDocument): nunca se ejecuta nada ni se siguen recursos de la página.
 */
final class LinkPreviewParser
{
    public const int MAX_TITLE = 200;

    public const int MAX_DESCRIPTION = 300;

    /**
     * @return array{title: string|null, description: string|null}
     */
    public function parse(string $html, ?string $charset = null): array
    {
        $document = $this->document($html, $charset);

        if ($document === null) {
            return ['title' => null, 'description' => null];
        }

        $title = $this->meta($document, ['meta[property="og:title"]', 'meta[name="twitter:title"]'])
            ?? $this->clean($document->querySelector('title')?->textContent, self::MAX_TITLE);

        $description = $this->meta($document, ['meta[property="og:description"]', 'meta[name="twitter:description"]', 'meta[name="description"]'], self::MAX_DESCRIPTION);

        return ['title' => $title, 'description' => $description];
    }

    private function document(string $html, ?string $charset): ?HTMLDocument
    {
        $options = LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS;

        try {
            return HTMLDocument::createFromString($html, $options, $this->encoding($charset));
        } catch (Throwable) {
            try {
                return HTMLDocument::createFromString($html, $options, 'UTF-8');
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Codificación del Content-Type si PHP la conoce; si no, que la detecte el parser.
     */
    private function encoding(?string $charset): ?string
    {
        if ($charset === null || $charset === '') {
            return null;
        }

        $charset = strtoupper(trim($charset, " \t\"'"));

        return in_array($charset, array_map('strtoupper', mb_list_encodings()), true) ? $charset : null;
    }

    /**
     * @param  list<string>  $selectors
     */
    private function meta(HTMLDocument $document, array $selectors, int $limit = self::MAX_TITLE): ?string
    {
        foreach ($selectors as $selector) {
            $element = $document->querySelector($selector);
            $value = $element instanceof Element ? $this->clean($element->getAttribute('content'), $limit) : null;

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Texto limpio: sin etiquetas ni caracteres de control, espacios colapsados y recortado.
     */
    private function clean(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 1)).'…' : $value;
    }
}
