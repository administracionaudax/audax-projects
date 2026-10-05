<?php

namespace App\Domain\Weeklies\Tasks;

/**
 * Las notas de una tarea en «Mi espacio» (F-060, D-203) son su descripción, editada como texto plano:
 * una línea por párrafo (`<p>`), como la escribe el editor de la tarea al pulsar Intro.
 *
 * Una descripción con formato (negritas, listas, enlaces, menciones…) no se puede editar como texto
 * plano sin perderlo: `isPlain()` lo dice y la pantalla la enseña en solo lectura, con el enlace a la
 * tarea. Las funciones son puras.
 */
final class TaskNotes
{
    /** Máximo de una nota en «Mi espacio» (texto plano; la descripción admite 50.000 de HTML). */
    public const int MAX_LENGTH = 10_000;

    /**
     * ¿Es la descripción solo párrafos y saltos de línea (sin formato que se perdería)?
     */
    public static function isPlain(?string $html): bool
    {
        if ($html === null || trim($html) === '') {
            return true;
        }

        $rest = preg_replace('#</?p>|<br\s*/?>#i', '', $html) ?? $html;

        return ! str_contains($rest, '<');
    }

    /**
     * La descripción como texto plano: un párrafo por línea; un `<br>` también es un salto.
     */
    public static function toPlain(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</p>\s*<p>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</?p>#i', '', $text) ?? $text;
        $text = strip_tags($text);

        return rtrim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * El texto plano como descripción: cada línea, un párrafo (las vacías, párrafos vacíos). Sin
     * texto, null (la tarea se queda sin descripción).
     */
    public static function toHtml(string $text): ?string
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $text));

        if (trim($text) === '') {
            return null;
        }

        $lines = array_map(
            fn (string $line): string => '<p>'.htmlspecialchars(rtrim($line), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>',
            explode("\n", $text),
        );

        return implode('', $lines);
    }
}
