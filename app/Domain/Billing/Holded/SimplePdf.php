<?php

namespace App\Domain\Billing\Holded;

/**
 * Un PDF mínimo y válido de una página con texto (Fase 12, F1): el «original» de las facturas de
 * FakeHolded en los tests y en local. Nunca se usa con datos reales: el PDF real llega de Holded.
 */
final class SimplePdf
{
    /**
     * @param  list<string>  $lines
     */
    public static function make(string $title, array $lines = []): string
    {
        $text = "BT\n/F1 18 Tf\n56 780 Td\n(".self::escape($title).") Tj\n/F1 10 Tf\n0 -28 Td\n";
        foreach ($lines as $line) {
            $text .= '('.self::escape($line).") Tj\n0 -16 Td\n";
        }
        $text .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text.'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function escape(string $text): string
    {
        $latin = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $latin);
    }
}
