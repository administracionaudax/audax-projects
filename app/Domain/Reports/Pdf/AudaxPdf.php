<?php

namespace App\Domain\Reports\Pdf;

use FPDF;

/**
 * Documento PDF con la marca de Audax Studio (D-045): FPDF 1.9 (MIT) con las fuentes estándar del
 * PDF (Helvetica, sin incrustar) en Windows-1252, así que los acentos, la ñ, ¿, ¡ y el € salen
 * bien; lo que no existe en esa codificación se sustituye por «?». La marca va en el logotipo
 * (vectorial, el mismo trazado que resources/js/components/app-logo.tsx) y en los colores del tema.
 *
 * Añade a FPDF la cabecera y el pie de cada página, el logotipo y filas de tabla con texto que
 * salta de línea y repite la cabecera de la tabla al cambiar de página.
 */
class AudaxPdf extends FPDF
{
    /** Navy de marca (#001B39): titulares y logotipo. */
    public const array NAVY = [0, 27, 57];

    /** Azul Audax (#0171FF): barras (D-011: permitido en barras y en la serie 1). */
    public const array BLUE = [1, 113, 255];

    /** Azul Audax al 35 % sobre blanco: lo que va dentro de la bolsa sin estar en el listado. */
    public const array BLUE_LIGHT = [166, 205, 255];

    /** Rojo de estado del tema claro (--danger, #B43A36): el exceso, siempre en rojo (SPEC §8.6). */
    public const array DANGER = [180, 58, 54];

    /** Rojo de estado al 35 % sobre blanco: exceso de horas que no están en el listado. */
    public const array DANGER_LIGHT = [229, 186, 185];

    /** Texto secundario (#56667A, D-011). */
    public const array MUTED = [86, 102, 122];

    /** Bordes (navy al 20 % sobre blanco). */
    public const array BORDER = [204, 209, 215];

    /** Fondo de las zonas secundarias (navy al 4 % sobre blanco). */
    public const array SURFACE = [245, 246, 247];

    /** Fondo de la barra de consumo. */
    public const array TRACK = [226, 229, 233];

    /**
     * Trazado del logotipo «AUDAX» (viewBox 500 × 83), copiado de app-logo.tsx (LOGO_PATH).
     */
    public const string LOGO_PATH = 'M47.4054 82.8776H0L23.7633 41.3776L47.4054 0L71.1688 41.5L94.8109 82.8776H47.4054ZM391.246 82.8776L367.604 41.5L343.841 0L320.078 41.5L296.314 83H391.246V82.8776ZM291.707 41.5C291.707 18.6077 273.278 0 250.606 0H209.505V83H250.606C273.278 83 291.707 64.3923 291.707 41.5ZM144.399 82.8776C167.071 82.8776 185.499 64.3923 185.499 41.3776V0H103.298V41.5C103.298 64.3923 121.726 82.8776 144.399 82.8776C144.399 83 144.399 83 144.399 82.8776ZM500 0H464.234L452.595 20.444L440.955 0H405.189L428.831 41.5L405.189 82.8776H440.955L452.595 62.4336L464.234 82.8776H500L476.237 41.3776L500 0Z';

    public const float LOGO_WIDTH = 500.0;

    public const float LOGO_HEIGHT = 83.0;

    /** @var array{widths: list<float>, cells: list<string>, aligns: list<string>}|null */
    private ?array $tableHeader = null;

    public function __construct(
        private readonly string $companyName,
        private readonly string $footerLabel,
        private readonly string $pageLabel,
    ) {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages('{nb}');
        $this->SetCreator($companyName, true);
        $this->SetAuthor($companyName, true);
    }

    /**
     * UTF-8 → Windows-1252 (la codificación de las fuentes estándar de FPDF), sin caracteres de control.
     */
    public static function encode(?string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);

        return (string) mb_convert_encoding($clean, 'Windows-1252', 'UTF-8');
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function textColor(array $rgb): void
    {
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillColor(array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function drawColor(array $rgb): void
    {
        $this->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
    }

    public function contentWidth(): float
    {
        return $this->w - $this->lMargin - $this->rMargin;
    }

    public function Header(): void
    {
        $this->logo($this->lMargin, 12, 5.5);

        $this->SetFont('Helvetica', '', 9);
        $this->textColor(self::MUTED);
        $this->SetXY($this->lMargin, 12);
        $this->Cell($this->contentWidth(), 5.5, self::encode($this->companyName), 0, 0, 'R');

        $this->drawColor(self::BORDER);
        $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, 21, $this->w - $this->rMargin, 21);
        $this->SetY(26);
        $this->textColor(self::NAVY);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 7.5);
        $this->textColor(self::MUTED);
        $this->Cell($this->contentWidth() * 0.7, 4, self::encode($this->footerLabel), 0, 0, 'L');
        $this->Cell($this->contentWidth() * 0.3, 4, self::encode(strtr($this->pageLabel, [':page' => (string) $this->PageNo(), ':pages' => '{nb}'])), 0, 0, 'R');
    }

    /**
     * Logotipo «AUDAX» en vectorial, en navy, con la esquina superior izquierda en ($x, $y) mm.
     */
    public function logo(float $x, float $y, float $height): void
    {
        $scale = $height / self::LOGO_HEIGHT;
        $this->fillColor(self::NAVY);
        $this->_out($this->svgPathToPdf(self::LOGO_PATH, $x, $y, $scale).' f');
    }

    /**
     * Convierte un trazado SVG absoluto (M, L, H, V, C, Z) a operadores de trazado PDF, en puntos y
     * con el eje Y hacia arriba.
     */
    public function svgPathToPdf(string $path, float $x, float $y, float $scale): string
    {
        preg_match_all('/([MLHVCZ])([^MLHVCZ]*)/', $path, $commands, PREG_SET_ORDER);

        $ops = [];
        $cx = 0.0;
        $cy = 0.0;
        $point = fn (float $px, float $py): string => sprintf('%.2F %.2F', ($x + $px * $scale) * $this->k, ($this->h - ($y + $py * $scale)) * $this->k);

        foreach ($commands as [, $command, $args]) {
            preg_match_all('/-?\d*\.?\d+(?:e-?\d+)?/i', $args, $numbers);
            $n = array_map('floatval', $numbers[0]);

            switch ($command) {
                case 'M':
                case 'L':
                    for ($i = 0; $i + 1 < count($n); $i += 2) {
                        [$cx, $cy] = [$n[$i], $n[$i + 1]];
                        $ops[] = $point($cx, $cy).($command === 'M' && $i === 0 ? ' m' : ' l');
                    }
                    break;
                case 'H':
                    foreach ($n as $value) {
                        $cx = $value;
                        $ops[] = $point($cx, $cy).' l';
                    }
                    break;
                case 'V':
                    foreach ($n as $value) {
                        $cy = $value;
                        $ops[] = $point($cx, $cy).' l';
                    }
                    break;
                case 'C':
                    for ($i = 0; $i + 5 < count($n); $i += 6) {
                        $ops[] = $point($n[$i], $n[$i + 1]).' '.$point($n[$i + 2], $n[$i + 3]).' '.$point($n[$i + 4], $n[$i + 5]).' c';
                        [$cx, $cy] = [$n[$i + 4], $n[$i + 5]];
                    }
                    break;
                case 'Z':
                    $ops[] = 'h';
                    break;
            }
        }

        return implode(' ', $ops);
    }

    /**
     * Cabecera de tabla (se repite al saltar de página mientras la tabla siga abierta).
     *
     * @param  list<float>  $widths
     * @param  list<string>  $cells  Texto UTF-8.
     * @param  list<string>  $aligns
     */
    public function tableHeader(array $widths, array $cells, array $aligns): void
    {
        $this->tableHeader = ['widths' => $widths, 'cells' => $cells, 'aligns' => $aligns];
        $this->drawTableHeader();
    }

    public function endTable(): void
    {
        $this->tableHeader = null;
    }

    /**
     * Fila de tabla con saltos de línea dentro de las celdas. Salta de página antes de partirla.
     *
     * @param  list<float>  $widths
     * @param  list<string>  $cells  Texto UTF-8.
     * @param  list<string>  $aligns
     * @param  array<int, array{0: int, 1: int, 2: int}>  $colors  Color de texto por columna.
     */
    public function tableRow(array $widths, array $cells, array $aligns, array $colors = [], bool $total = false, float $lineHeight = 4.2): void
    {
        $encoded = array_map(fn (string $cell): string => self::encode($cell), $cells);
        $lines = 1;
        foreach ($encoded as $i => $cell) {
            $lines = max($lines, $this->nbLines($widths[$i], $cell));
        }
        $height = $lines * $lineHeight + 1.6;

        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
            if ($this->tableHeader !== null) {
                $this->drawTableHeader();
            }
        }

        $x = $this->lMargin;
        $y = $this->GetY();

        if ($total) {
            $this->drawColor(self::NAVY);
            $this->SetLineWidth(0.3);
            $this->Line($x, $y, $x + array_sum($widths), $y);
        }

        foreach ($encoded as $i => $cell) {
            $this->textColor($colors[$i] ?? self::NAVY);
            $this->SetXY($x, $y + 0.8);
            $this->MultiCell($widths[$i], $lineHeight, $cell, 0, $aligns[$i] ?? 'L');
            $x += $widths[$i];
        }

        $this->SetXY($this->lMargin, $y + $height);

        if (! $total) {
            $this->drawColor(self::BORDER);
            $this->SetLineWidth(0.1);
            $this->Line($this->lMargin, $y + $height, $this->lMargin + array_sum($widths), $y + $height);
        }

        $this->textColor(self::NAVY);
    }

    /**
     * Líneas que ocupará un texto (ya en Windows-1252) en una celda de ancho $w (MultiCell).
     */
    public function nbLines(float $w, string $text): int
    {
        if (! isset($this->CurrentFont)) {
            return 1;
        }

        /** @var array<string, int> $cw */
        $cw = $this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $text);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }

        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $lines = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $lines++;

                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $lines++;
            } else {
                $i++;
            }
        }

        return $lines;
    }

    private function drawTableHeader(): void
    {
        if ($this->tableHeader === null) {
            return;
        }

        ['widths' => $widths, 'cells' => $cells, 'aligns' => $aligns] = $this->tableHeader;
        $this->SetFont('Helvetica', '', 8);
        $this->fillColor(self::SURFACE);
        $this->textColor(self::MUTED);
        $x = $this->lMargin;
        $y = $this->GetY();
        foreach ($cells as $i => $cell) {
            $this->SetXY($x, $y);
            $this->Cell($widths[$i], 6, self::encode($cell), 0, 0, $aligns[$i] ?? 'L', true);
            $x += $widths[$i];
        }
        $this->SetXY($this->lMargin, $y + 6);
        $this->textColor(self::NAVY);
        $this->SetFont('Helvetica', '', 8.5);
    }
}
