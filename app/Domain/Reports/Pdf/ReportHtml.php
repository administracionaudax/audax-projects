<?php

namespace App\Domain\Reports\Pdf;

use App\Domain\Reports\Delivery\Documents\ReportPdf;
use Illuminate\Support\Facades\Vite;

/**
 * HTML del PDF y de la impresión de un informe (D-140): la vista del informe (que extiende
 * reports.pdf.layout) con TODO dentro, para que Gotenberg no tenga que cargar nada y la pestaña de
 * imprimir sea exactamente el mismo documento:
 * - DM Sans 400, 500 y 600 (woff2 de @fontsource/dm-sans, OFL, copiados en resources/fonts/dm-sans)
 *   como data: URI,
 * - la hoja de documentos A4 de Audax (resources/views/reports/pdf/audax-doc.css, copia literal
 *   del kit) y los ajustes de los informes (report.css),
 * - el logotipo (public/brand/audax-logo.svg) en línea.
 * Con $print, además el diálogo de impresión al cargar (script con el nonce de la CSP).
 */
final class ReportHtml
{
    public const array FONT_WEIGHTS = [400, 500, 600];

    private static ?string $theme = null;

    private static ?string $logo = null;

    public function render(ReportPdf $pdf, bool $print = false): string
    {
        return view($pdf->view, [
            ...$pdf->data,
            'title' => $pdf->title,
            'landscape' => $pdf->landscape,
            'print' => $print,
            'nonce' => $print ? Vite::cspNonce() : null,
            'theme' => self::theme(),
            'logo' => self::logo(),
        ])->render();
    }

    /**
     * CSS completo: @font-face de DM Sans incrustada, la hoja de Audax y report.css.
     */
    public static function theme(): string
    {
        if (self::$theme !== null) {
            return self::$theme;
        }

        $fonts = '';
        foreach (self::FONT_WEIGHTS as $weight) {
            $file = resource_path("fonts/dm-sans/dm-sans-latin-{$weight}-normal.woff2");
            $fonts .= "@font-face{font-family:'DM Sans';font-style:normal;font-weight:{$weight};font-display:block;"
                .'src:url(data:font/woff2;base64,'.base64_encode((string) file_get_contents($file)).") format('woff2');}\n";
        }

        return self::$theme = $fonts
            .file_get_contents(resource_path('views/reports/pdf/audax-doc.css'))."\n"
            .file_get_contents(resource_path('views/reports/pdf/report.css'));
    }

    public static function logo(): string
    {
        return self::$logo ??= (string) preg_replace('/^<svg /', '<svg class="logo" ', trim((string) file_get_contents(public_path('brand/audax-logo.svg'))));
    }
}
