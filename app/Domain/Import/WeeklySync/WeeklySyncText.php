<?php

namespace App\Domain\Import\WeeklySync;

use App\Support\RichText;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Textos de WeeklySync al formato de Audax: Markdown (o HTML) → HTML saneado con RichText, y las
 * menciones `@[Nombre](user:uuid)` → `<span data-type="mention" data-id="…">` con el id de Audax
 * (o el nombre en texto si la persona se queda fuera).
 *
 * Antes de sanear se **normaliza** (10.9b): el saneador borra las etiquetas que no admite CON su
 * texto, y el editor de WeeklySync (`execCommand`) producía `<h1>`, `<h2>`, `<b>`, `<i>`, `<div>` e
 * `<img>`, y su barra de las sugerencias, Markdown con `#` y `##`. Así ningún texto se pierde:
 * h1/h2 → h3, h5/h6 → h4, b → strong, i → em, strike/del → s, div y demás bloques → p (o se
 * desenvuelven si llevan bloques dentro), img → enlace (o su texto alternativo), tablas → párrafos
 * y cualquier otra etiqueta desconocida se desenvuelve conservando su contenido.
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

        $html = strtr(self::normalize($html), $mentions);

        return RichText::sanitize(Str::limit($html, RichText::MAX_LENGTH, ''));
    }

    /** Etiquetas que cambian de nombre (las que el saneador no admite, a su equivalente). */
    private const array RENAME = [
        'h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4',
        'b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'ins' => 'u',
    ];

    /** Etiquetas que se borran con su contenido (no es texto del usuario). */
    private const array DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math',
        'head', 'title', 'meta', 'link', 'input', 'button', 'select', 'textarea', 'video', 'audio',
    ];

    /** Bloques que pasan a párrafo (o se desenvuelven si ya llevan bloques dentro). */
    private const array BLOCKS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure',
        'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'dl', 'dt', 'dd',
        'center', 'address', 'details', 'summary', 'form', 'fieldset', 'legend',
    ];

    /** Lo que admite RichText tal cual. */
    private const array KEEP = [
        'p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h3',
        'h4', 'hr', 'a', 'span',
    ];

    /** Bloques de RichText: un párrafo no puede llevarlos dentro. */
    private const array BLOCK_CHILDREN = ['p', 'ul', 'ol', 'li', 'h3', 'h4', 'blockquote', 'pre', 'hr'];

    /**
     * HTML de WeeklySync → HTML con solo las etiquetas de RichText, sin perder texto.
     */
    public static function normalize(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><div id="ws-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('ws-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        self::normalizeChildren($root);
        self::wrapLooseInline($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    private static function normalizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::normalizeElement($child);
            }
        }
    }

    private static function normalizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if ($tag === 'img') {
            self::replaceImage($element);

            return;
        }

        self::normalizeChildren($element);

        if (isset(self::RENAME[$tag])) {
            self::rename($element, self::RENAME[$tag]);

            return;
        }

        if ($tag === 'td' || $tag === 'th') {
            $element->appendChild($element->ownerDocument->createTextNode(' '));
            self::unwrap($element);

            return;
        }

        if (in_array($tag, self::BLOCKS, true)) {
            self::hasBlockChildren($element) ? self::unwrap($element) : self::rename($element, 'p');

            return;
        }

        if ($tag === 'span' && $element->getAttribute('data-type') !== 'mention') {
            self::unwrap($element);

            return;
        }

        if (! in_array($tag, self::KEEP, true)) {
            self::unwrap($element);
        }
    }

    /** Una imagen se queda como enlace a su dirección (si es web) o como su texto alternativo. */
    private static function replaceImage(DOMElement $image): void
    {
        $document = $image->ownerDocument;
        $alt = trim($image->getAttribute('alt'));
        $src = trim($image->getAttribute('src'));
        $label = $alt !== '' ? $alt : __('weeklies.import_image');

        if (preg_match('#^https?://#i', $src) === 1) {
            $link = $document->createElement('a');
            $link->setAttribute('href', $src);
            $link->appendChild($document->createTextNode($label));
            $replacement = $link;
        } else {
            $replacement = $document->createTextNode($alt !== '' ? $alt : '['.$label.']');
        }

        $image->parentNode?->replaceChild($replacement, $image);
    }

    private static function rename(DOMElement $element, string $tag): void
    {
        $replacement = $element->ownerDocument->createElement($tag);

        if ($tag === 'a' || $tag === 'span') {
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $replacement->setAttribute($attribute->nodeName, (string) $attribute->nodeValue);
            }
        }

        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }

        $element->parentNode?->replaceChild($replacement, $element);
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function hasBlockChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCK_CHILDREN, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El texto suelto junto a bloques («Hola<div>segunda</div>», lo que genera un contentEditable)
     * va en su propio párrafo, para que no se pegue al siguiente.
     */
    private static function wrapLooseInline(DOMElement $root): void
    {
        if (! self::hasBlockChildren($root)) {
            return;
        }

        $document = $root->ownerDocument;
        $paragraph = null;

        foreach (iterator_to_array($root->childNodes) as $child) {
            $isBlock = $child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCK_CHILDREN, true);

            if ($isBlock) {
                $paragraph = null;

                continue;
            }

            if ($child instanceof DOMText && trim($child->textContent) === '' && $paragraph === null) {
                continue;
            }

            if ($paragraph === null) {
                $paragraph = $document->createElement('p');
                $root->insertBefore($paragraph, $child);
            }

            $paragraph->appendChild($child);
        }
    }

    /**
     * Texto plano de una sola línea o párrafo (títulos, resúmenes de versión, descripciones).
     */
    public static function plain(mixed $text, int $limit = 0): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", is_scalar($text) ? (string) $text : ''));

        return $limit > 0 ? Str::limit($text, $limit, '') : $text;
    }

    /**
     * HTML si empieza por un bloque o lleva etiquetas de formato en cualquier sitio (un contentEditable
     * produce «Hola<div>…</div>»); si no, Markdown.
     */
    private static function looksLikeHtml(string $text): bool
    {
        return preg_match('#^\s*<(p|div|h[1-6]|ul|ol|blockquote|pre|table|br)\b#i', $text) === 1
            || preg_match('#<(/?(p|div|h[1-6]|ul|ol|li|blockquote|pre|table|b|i|strong|em|u|s|strike|a|span|font)\b[^>]*|br\s*/?|hr\s*/?|img\b[^>]*)>#i', $text) === 1;
    }
}
