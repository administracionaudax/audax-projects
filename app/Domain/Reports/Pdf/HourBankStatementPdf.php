<?php

namespace App\Domain\Reports\Pdf;

use Carbon\CarbonImmutable;

/**
 * Maqueta del PDF de consumo de una bolsa (D-045; R2) a partir de HourBankStatement::build():
 * logotipo y nombre de la empresa, cliente, proyecto y bolsa, cifras y barra de consumo (dentro de
 * la bolsa en azul y el exceso aparte, en rojo; lo que no sale en el listado, en tono claro),
 * consumo por mes y listado de las entradas aprobadas o bloqueadas con sus totales. Los importes,
 * solo si el statement los trae. Todos los textos, con __() (lang/es/reports.php, r2.pdf).
 * En modo portal (statement con `portal`, D-066), la etiqueta de las horas, la nota y el aviso de
 * «sin horas» dicen lo que ve el cliente (lang/es/portal.php, banks.pdf).
 */
final class HourBankStatementPdf
{
    /** Longitud máxima de una descripción en el listado (una fila nunca ocupa más de una página). */
    public const int MAX_DESCRIPTION = 600;

    /**
     * @param  array{
     *     company: string, client: string|null, project: array{code: string, name: string},
     *     bank: array{name: string, start_date: string, end_date: string|null, status: string, total_minutes: int},
     *     generated_at: CarbonImmutable,
     *     figures: array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float},
     *     months: list<array{month: string, in_bank: int, overage: int}>,
     *     entries: list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>,
     *     financials: array{price_amount: string|null, rate: string|null, income: string}|null,
     *     partial: bool,
     *     portal?: array{visibility: string}
     * }  $statement  Con `portal` (HourBankStatement::forPortal, D-066), los textos del portal.
     */
    public function render(array $statement, bool $compress = true): string
    {
        $pdf = new AudaxPdf(
            $statement['company'],
            $statement['company'].' · '.$statement['project']['code'].' · '.$statement['bank']['name'],
            self::t('reports.r2.pdf.page'),
        );
        $pdf->SetCompression($compress);
        $pdf->SetTitle(self::t('reports.r2.pdf.title').' · '.$statement['bank']['name'], true);
        $pdf->SetSubject($statement['project']['code'].' · '.$statement['project']['name'], true);
        $pdf->AddPage();

        $this->title($pdf, $statement);
        $this->details($pdf, $statement);
        $this->figures($pdf, $statement);
        $this->bar($pdf, $statement);
        $this->notes($pdf, $statement);

        if ($statement['financials'] !== null) {
            $this->financials($pdf, $statement['financials']);
        }

        $this->months($pdf, $statement['months']);
        $this->entries($pdf, $statement['entries'], $statement['figures'], self::t(isset($statement['portal']) ? 'portal.banks.pdf.no_entries' : 'reports.r2.pdf.no_entries'));

        return $pdf->Output('S');
    }

    public static function minutes(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return $sign.intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Exceso con su signo: «+1:40», o «0:00» si no hay.
     */
    public static function overage(int $minutes): string
    {
        return ($minutes > 0 ? '+' : '').self::minutes($minutes);
    }

    public static function money(?string $amount): string
    {
        return number_format((float) ($amount ?? '0'), 2, ',', '.').' €';
    }

    public static function date(?string $date): string
    {
        return $date === null ? '' : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    public static function percent(float $ratio): string
    {
        return number_format($ratio * 100, 0, ',', '.').' %';
    }

    /**
     * «2026-09-01» → «Septiembre de 2026».
     */
    public static function month(string $month): string
    {
        $date = CarbonImmutable::parse($month);

        return self::t('reports.r2.pdf.month_year', [
            'month' => self::t('reports.r2.pdf.months.'.$date->month),
            'year' => (string) $date->year,
        ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function title(AudaxPdf $pdf, array $statement): void
    {
        $pdf->SetFont('Helvetica', '', 18);
        $pdf->textColor(AudaxPdf::NAVY);
        $pdf->Cell(0, 9, AudaxPdf::encode(self::t('reports.r2.pdf.title')), 0, 1);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->textColor(AudaxPdf::BLUE);
        $pdf->Cell(0, 6, AudaxPdf::encode($statement['bank']['name']), 0, 1);
        $pdf->Ln(3);
    }

    /**
     * @param  array{client: string|null, project: array{code: string, name: string}, bank: array{name: string, start_date: string, end_date: string|null, status: string, total_minutes: int}, generated_at: CarbonImmutable}  $statement
     */
    private function details(AudaxPdf $pdf, array $statement): void
    {
        $bank = $statement['bank'];
        $validity = $bank['end_date'] === null
            ? self::t('reports.r2.pdf.from', ['from' => self::date($bank['start_date'])])
            : self::t('reports.r2.pdf.from_to', ['from' => self::date($bank['start_date']), 'to' => self::date($bank['end_date'])]);

        $rows = [
            [self::t('reports.r2.pdf.client'), $statement['client'] ?? self::t('reports.r2.pdf.no_client'), self::t('reports.r2.pdf.validity'), $validity],
            [self::t('reports.r2.pdf.project'), $statement['project']['code'].' · '.$statement['project']['name'], self::t('reports.r2.pdf.status'), $bank['status']],
            [self::t('reports.r2.pdf.bank'), $bank['name'], self::t('reports.r2.pdf.generated'), $statement['generated_at']->format('d/m/Y H:i')],
        ];

        $half = $pdf->contentWidth() / 2;
        foreach ($rows as [$labelA, $valueA, $labelB, $valueB]) {
            $y = $pdf->GetY();
            foreach ([[$labelA, $valueA, 0.0], [$labelB, $valueB, $half]] as [$label, $value, $offset]) {
                $pdf->SetXY(15 + $offset, $y);
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->textColor(AudaxPdf::MUTED);
                $pdf->Cell(28, 5.5, AudaxPdf::encode($label));
                $pdf->SetFont('Helvetica', '', 9.5);
                $pdf->textColor(AudaxPdf::NAVY);
                $pdf->Cell($half - 30, 5.5, AudaxPdf::encode(self::fit($pdf, $value, $half - 30)));
            }
            $pdf->SetXY(15, $y + 5.5);
        }
        $pdf->Ln(4);
    }

    /**
     * Cifras: las de las horas aprobadas (las del listado), las que no salen en él (sin aprobar; en
     * un PDF parcial, también las de otras personas) y el saldo restante de la bolsa.
     *
     * En modo portal, «Horas aprobadas» (o «enviadas y aprobadas») según lo que ve el cliente.
     *
     * @param  array{bank: array{total_minutes: int}, figures: array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float}, partial: bool, portal?: array{visibility: string}}  $statement
     */
    private function figures(AudaxPdf $pdf, array $statement): void
    {
        $f = $statement['figures'];
        $pending = $f['pending_in_bank'] + $f['pending_overage'];
        $consumed = isset($statement['portal'])
            ? self::t('portal.banks.pdf.consumed.'.$statement['portal']['visibility'])
            : self::t('reports.r2.pdf.consumed');
        $items = [
            [self::t('reports.r2.pdf.contracted'), self::minutes($statement['bank']['total_minutes']), null, AudaxPdf::NAVY],
            [$consumed, self::minutes($f['consumed']), self::t('reports.r2.pdf.consumed_pct', ['pct' => self::percent($f['ratio'])]), AudaxPdf::NAVY],
            [self::t('reports.r2.pdf.in_bank'), self::minutes($f['in_bank']), null, AudaxPdf::NAVY],
            [self::t('reports.r2.pdf.overage'), self::overage($f['overage']), null, $f['overage'] > 0 ? AudaxPdf::DANGER : AudaxPdf::NAVY],
        ];
        if ($pending > 0) {
            $items[] = [
                self::t($statement['partial'] ? 'reports.r2.pdf.pending_partial' : 'reports.r2.pdf.pending'),
                self::minutes($pending),
                $f['pending_overage'] > 0 ? self::t('reports.r2.pdf.pending_overage_detail', ['minutes' => self::overage($f['pending_overage'])]) : null,
                AudaxPdf::NAVY,
            ];
        }
        $items[] = [self::t('reports.r2.pdf.remaining'), self::minutes($f['remaining']), null, AudaxPdf::NAVY];

        $width = $pdf->contentWidth() / count($items);
        $y = $pdf->GetY();
        $pdf->fillColor(AudaxPdf::SURFACE);
        $pdf->Rect(15, $y, $pdf->contentWidth(), 19, 'F');

        foreach ($items as $i => [$label, $value, $detail, $color]) {
            $x = 15 + $i * $width;
            $pdf->SetXY($x + 3, $y + 2.5);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->textColor(AudaxPdf::MUTED);
            $pdf->Cell($width - 4, 4, AudaxPdf::encode($label));
            $pdf->SetXY($x + 3, $y + 7);
            $pdf->SetFont('Helvetica', '', 14);
            $pdf->textColor($color);
            $pdf->Cell($width - 4, 6.5, AudaxPdf::encode($value));
            if ($detail !== null) {
                $pdf->SetXY($x + 3, $y + 13.5);
                $pdf->SetFont('Helvetica', '', 7.5);
                $pdf->textColor(AudaxPdf::MUTED);
                $pdf->Cell($width - 4, 4, AudaxPdf::encode($detail));
            }
        }

        $pdf->SetXY(15, $y + 23);
        $pdf->textColor(AudaxPdf::NAVY);
    }

    /**
     * Barra de consumo como la de la app (HourBankMeter): lo que va dentro de la bolsa hasta el total
     * y el exceso a continuación, en rojo; una marca navy señala el total contratado. Lo que no sale
     * en el listado (sin aprobar), en el tono claro de cada color, tras lo aprobado.
     *
     * @param  array{bank: array{total_minutes: int}, figures: array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float}, partial: bool}  $statement
     */
    private function bar(AudaxPdf $pdf, array $statement): void
    {
        $total = $statement['bank']['total_minutes'];
        $f = $statement['figures'];
        $overageTotal = $f['overage'] + $f['pending_overage'];
        $scale = max($total + $overageTotal, $f['in_bank'] + $f['pending_in_bank'], 1);
        $width = $pdf->contentWidth();
        $x = 15.0;
        $y = $pdf->GetY();
        $height = 5.0;

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->textColor(AudaxPdf::MUTED);
        $pdf->Cell(0, 4, AudaxPdf::encode(self::t('reports.r2.pdf.bar_label')), 0, 1);
        $y = $pdf->GetY() + 1;

        $pdf->fillColor(AudaxPdf::TRACK);
        $pdf->Rect($x, $y, $width, $height, 'F');

        $inside = min($f['in_bank'], $total);
        if ($inside > 0) {
            $pdf->fillColor(AudaxPdf::BLUE);
            $pdf->Rect($x, $y, $width * $inside / $scale, $height, 'F');
        }

        $pendingInside = min($f['pending_in_bank'], max($total - $inside, 0));
        if ($pendingInside > 0) {
            $pdf->fillColor(AudaxPdf::BLUE_LIGHT);
            $pdf->Rect($x + $width * $inside / $scale, $y, $width * $pendingInside / $scale, $height, 'F');
        }

        $overageStart = $x + $width * $total / $scale + 0.4;
        if ($f['overage'] > 0) {
            $pdf->fillColor(AudaxPdf::DANGER);
            $pdf->Rect($overageStart, $y, max($width * $f['overage'] / $scale - 0.4, 0.3), $height, 'F');
        }
        if ($f['pending_overage'] > 0) {
            $pdf->fillColor(AudaxPdf::DANGER_LIGHT);
            $pdf->Rect($overageStart + $width * $f['overage'] / $scale, $y, max($width * $f['pending_overage'] / $scale - 0.4, 0.3), $height, 'F');
        }

        if ($total > 0) {
            $pdf->drawColor(AudaxPdf::NAVY);
            $pdf->SetLineWidth(0.4);
            $mark = $x + $width * $total / $scale;
            $pdf->Line($mark, $y - 1, $mark, $y + $height + 1);
        }

        // Leyenda: cuadrado de color + texto (nunca solo color); salta de línea si no cabe.
        $legend = [
            [AudaxPdf::BLUE, self::t('reports.r2.pdf.in_bank').': '.self::minutes($f['in_bank'])],
        ];
        if ($f['pending_in_bank'] > 0) {
            $legend[] = [AudaxPdf::BLUE_LIGHT, self::t($statement['partial'] ? 'reports.r2.pdf.legend_pending_partial' : 'reports.r2.pdf.legend_pending').': '.self::minutes($f['pending_in_bank'])];
        }
        if ($f['overage'] > 0) {
            $legend[] = [AudaxPdf::DANGER, self::t('reports.r2.pdf.overage').': '.self::overage($f['overage'])];
        }
        if ($f['pending_overage'] > 0) {
            $legend[] = [AudaxPdf::DANGER_LIGHT, self::t($statement['partial'] ? 'reports.r2.pdf.legend_pending_overage_partial' : 'reports.r2.pdf.legend_pending_overage').': '.self::overage($f['pending_overage'])];
        }
        $legend[] = [AudaxPdf::TRACK, self::t('reports.r2.pdf.remaining').': '.self::minutes($f['remaining'])];

        $pdf->SetFont('Helvetica', '', 8);
        $lx = $x;
        $ly = $y + $height + 2.5;
        foreach ($legend as [$color, $label]) {
            $text = AudaxPdf::encode($label);
            $itemWidth = 4.5 + $pdf->GetStringWidth($text) + 6;
            if ($lx > $x && $lx + $itemWidth > $x + $width) {
                $lx = $x;
                $ly += 5;
            }
            $pdf->fillColor($color);
            $pdf->Rect($lx, $ly + 1, 3, 3, 'F');
            $pdf->SetXY($lx + 4.5, $ly);
            $pdf->textColor(AudaxPdf::NAVY);
            $pdf->Cell($itemWidth - 4.5, 5, $text);
            $lx += $itemWidth;
        }

        $pdf->SetXY(15, $ly + 6.5);
    }

    /**
     * En modo portal, qué horas ve el cliente según su ajuste (D-064).
     *
     * @param  array{generated_at: CarbonImmutable, figures: array{pending_in_bank: int, pending_overage: int}, partial: bool, portal?: array{visibility: string}}  $statement
     */
    private function notes(AudaxPdf $pdf, array $statement): void
    {
        $date = ['date' => $statement['generated_at']->format('d/m/Y')];
        $note = isset($statement['portal'])
            ? self::t('portal.banks.pdf.note.'.$statement['portal']['visibility'], $date)
            : self::t('reports.r2.pdf.approved_only', $date);

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->textColor(AudaxPdf::MUTED);
        $pdf->MultiCell(0, 4, AudaxPdf::encode($note));

        $pending = $statement['figures']['pending_in_bank'] + $statement['figures']['pending_overage'];
        if ($pending > 0 && ! $statement['partial']) {
            $pdf->Ln(1);
            $pdf->MultiCell(0, 4, AudaxPdf::encode(self::t('reports.r2.pdf.pending_note', ['hours' => self::minutes($pending)])));
        }

        if ($statement['partial']) {
            $pdf->Ln(1);
            $pdf->textColor(AudaxPdf::DANGER);
            $pdf->MultiCell(0, 4, AudaxPdf::encode(self::t('reports.r2.pdf.partial')));
        }

        $pdf->textColor(AudaxPdf::NAVY);
        $pdf->Ln(4);
    }

    /**
     * @param  array{price_amount: string|null, rate: string|null, income: string}  $financials
     */
    private function financials(AudaxPdf $pdf, array $financials): void
    {
        $this->heading($pdf, self::t('reports.r2.pdf.financials'));
        $rows = [
            [self::t('reports.r2.pdf.price'), $financials['price_amount'] !== null ? self::money($financials['price_amount']) : self::t('reports.r2.pdf.no_price')],
            [self::t('reports.r2.pdf.rate'), $financials['rate'] !== null ? self::t('reports.r2.pdf.per_hour', ['amount' => self::money($financials['rate'])]) : self::t('reports.r2.pdf.rate_person')],
            [self::t('reports.r2.pdf.income'), self::money($financials['income'])],
        ];

        $pdf->SetFont('Helvetica', '', 9);
        foreach ($rows as [$label, $value]) {
            $pdf->textColor(AudaxPdf::MUTED);
            $pdf->Cell(60, 5.5, AudaxPdf::encode($label));
            $pdf->textColor(AudaxPdf::NAVY);
            $pdf->Cell(0, 5.5, AudaxPdf::encode($value), 0, 1);
        }
        $pdf->Ln(4);
    }

    /**
     * @param  list<array{month: string, in_bank: int, overage: int}>  $months
     */
    private function months(AudaxPdf $pdf, array $months): void
    {
        if ($months === []) {
            return;
        }

        $this->heading($pdf, self::t('reports.r2.pdf.monthly'));
        $widths = [70.0, 40.0, 40.0, 30.0];
        $aligns = ['L', 'R', 'R', 'R'];
        $pdf->tableHeader($widths, [self::t('reports.r2.pdf.month'), self::t('reports.r2.pdf.in_bank'), self::t('reports.r2.pdf.overage'), self::t('reports.r2.pdf.total')], $aligns);

        $inBank = 0;
        $overage = 0;
        foreach ($months as $month) {
            $inBank += $month['in_bank'];
            $overage += $month['overage'];
            $pdf->tableRow($widths, [
                self::month($month['month']),
                self::minutes($month['in_bank']),
                self::overage($month['overage']),
                self::minutes($month['in_bank'] + $month['overage']),
            ], $aligns, [2 => $month['overage'] > 0 ? AudaxPdf::DANGER : AudaxPdf::NAVY]);
        }

        $pdf->tableRow($widths, [
            self::t('reports.r2.pdf.total'),
            self::minutes($inBank),
            self::overage($overage),
            self::minutes($inBank + $overage),
        ], $aligns, [2 => $overage > 0 ? AudaxPdf::DANGER : AudaxPdf::NAVY], total: true);
        $pdf->endTable();
        $pdf->Ln(6);
    }

    /**
     * @param  list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>  $entries
     * @param  array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float}  $figures
     */
    private function entries(AudaxPdf $pdf, array $entries, array $figures, string $empty): void
    {
        $this->heading($pdf, self::t('reports.r2.pdf.entries'));

        if ($entries === []) {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->textColor(AudaxPdf::MUTED);
            $pdf->Cell(0, 6, AudaxPdf::encode($empty), 0, 1);

            return;
        }

        $widths = [19.0, 32.0, 45.0, 16.0, 16.0, 52.0];
        $aligns = ['L', 'L', 'L', 'R', 'R', 'L'];
        $pdf->tableHeader($widths, [
            self::t('reports.r2.pdf.date'), self::t('reports.r2.pdf.person'), self::t('reports.r2.pdf.task'),
            self::t('reports.r2.pdf.in_bank_short'), self::t('reports.r2.pdf.overage'), self::t('reports.r2.pdf.description'),
        ], $aligns);

        foreach ($entries as $entry) {
            $description = mb_strlen($entry['description']) > self::MAX_DESCRIPTION
                ? mb_substr($entry['description'], 0, self::MAX_DESCRIPTION).'…'
                : $entry['description'];

            $pdf->tableRow($widths, [
                self::date($entry['date']),
                $entry['person'],
                $entry['task'],
                self::minutes($entry['in_bank']),
                self::overage($entry['overage']),
                $description,
            ], $aligns, [4 => $entry['overage'] > 0 ? AudaxPdf::DANGER : AudaxPdf::NAVY]);
        }

        $pdf->tableRow($widths, [
            self::t('reports.r2.pdf.total'), '', '',
            self::minutes($figures['in_bank']),
            self::overage($figures['overage']),
            '',
        ], $aligns, [4 => $figures['overage'] > 0 ? AudaxPdf::DANGER : AudaxPdf::NAVY], total: true);
        $pdf->endTable();
    }

    private function heading(AudaxPdf $pdf, string $text): void
    {
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }

        $pdf->SetFont('Helvetica', '', 12);
        $pdf->textColor(AudaxPdf::NAVY);
        $pdf->Cell(0, 7, AudaxPdf::encode($text), 0, 1);
        $pdf->Ln(1);
    }

    /**
     * Recorta un texto (UTF-8) para que quepa en una línea de $width mm, con «…».
     */
    private static function fit(AudaxPdf $pdf, string $text, float $width): string
    {
        if ($pdf->GetStringWidth(AudaxPdf::encode($text)) <= $width - 2) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $pdf->GetStringWidth(AudaxPdf::encode($text.'…')) > $width - 2) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.'…';
    }
}
