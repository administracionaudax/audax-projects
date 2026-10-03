<?php

namespace App\Domain\Reports\Pdf;

use App\Domain\Reports\Delivery\Documents\PdfTable;
use App\Domain\Reports\Delivery\Documents\ReportPdf;
use Carbon\CarbonImmutable;

/**
 * Documento del PDF de consumo de una bolsa (D-045, D-095; Fase 9, D-140) a partir de
 * HourBankStatement::build() (interno) o ::forPortal() (portal): la misma información que el PDF
 * de FPDF al que sustituye, con la hoja de documentos de Audax:
 * - portada con el cliente, el proyecto, la bolsa, su vigencia y su estado,
 * - cifras (contratadas, aprobadas con su %, dentro, exceso, lo que no sale en el listado y el
 *   saldo), la barra de consumo con su leyenda y las notas (solo aprobadas; sin aprobar; parcial),
 * - los datos económicos, solo si el statement los trae (PDF de uso interno con view-financials),
 * - el consumo por mes y el detalle de las horas, con sus totales.
 * En modo portal (statement con `portal`, D-095) no hay bloque «sin aprobar» (sus pending_* son 0)
 * y los textos de las horas y de la nota son los del portal.
 *
 * @phpstan-type Statement array{
 *     company: string, client: string|null, project: array{code: string, name: string},
 *     bank: array{name: string, start_date: string, end_date: string|null, status: string, total_minutes: int},
 *     generated_at: CarbonImmutable,
 *     figures: array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float},
 *     months: list<array{month: string, in_bank: int, overage: int}>,
 *     entries: list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>,
 *     financials: array{price_amount: string|null, rate: string|null, income: string}|null,
 *     partial: bool,
 *     portal?: array{visibility: string}
 * }
 */
final class HourBankStatementView
{
    /** Longitud máxima de una descripción en el listado. */
    public const int MAX_DESCRIPTION = 600;

    /**
     * @param  Statement  $statement
     * @param  string  $filename  sin extensión
     */
    public static function make(array $statement, string $generatedBy, string $filename): ReportPdf
    {
        $bank = $statement['bank'];
        $f = $statement['figures'];
        $portal = isset($statement['portal']);
        $pending = $f['pending_in_bank'] + $f['pending_overage'];
        $date = $statement['generated_at']->format('d/m/Y');
        $validity = $bank['end_date'] === null
            ? self::t('reports.r2.pdf.from', ['from' => PdfFormat::date($bank['start_date'])])
            : self::t('reports.r2.pdf.from_to', ['from' => PdfFormat::date($bank['start_date']), 'to' => PdfFormat::date($bank['end_date'])]);

        $kpis = [
            ['label' => self::t('reports.r2.pdf.contracted'), 'value' => PdfFormat::minutes($bank['total_minutes']), 'detail' => null],
            ['label' => $portal ? self::t('portal.banks.pdf.consumed.'.$statement['portal']['visibility']) : self::t('reports.r2.pdf.consumed'),
                'value' => PdfFormat::minutes($f['consumed']), 'detail' => self::t('reports.r2.pdf.consumed_pct', ['pct' => PdfFormat::percent($f['ratio'], 0)])],
            ['label' => self::t('reports.r2.pdf.in_bank'), 'value' => PdfFormat::minutes($f['in_bank']), 'detail' => null],
            ['label' => self::t('reports.r2.pdf.overage'), 'value' => PdfFormat::overage($f['overage']), 'detail' => null, 'class' => $f['overage'] > 0 ? 'overage' : null],
        ];
        if ($pending > 0) {
            $kpis[] = ['label' => self::t($statement['partial'] ? 'reports.r2.pdf.pending_partial' : 'reports.r2.pdf.pending'), 'value' => PdfFormat::minutes($pending),
                'detail' => $f['pending_overage'] > 0 ? self::t('reports.r2.pdf.pending_overage_detail', ['minutes' => PdfFormat::overage($f['pending_overage'])]) : null];
        }
        $kpis[] = ['label' => self::t('reports.r2.pdf.remaining'), 'value' => PdfFormat::minutes($f['remaining']), 'detail' => null];

        $notes = [];
        if ($pending > 0 && ! $statement['partial']) {
            $notes[] = self::t('reports.r2.pdf.pending_note', ['hours' => PdfFormat::minutes($pending)]);
        }

        return new ReportPdf(
            view: 'reports.pdf.hour-bank',
            title: self::t('reports.r2.pdf.title').' · '.$bank['name'],
            filename: $filename,
            data: [
                'cover' => [
                    'kicker' => self::t('reports.r2.pdf.title'),
                    'title' => $bank['name'],
                    'subtitle' => $statement['project']['code'].' · '.$statement['project']['name'],
                    'facts' => [
                        [self::t('reports.r2.pdf.client'), $statement['client'] ?? self::t('reports.r2.pdf.no_client')],
                        [self::t('reports.r2.pdf.project'), $statement['project']['code'].' · '.$statement['project']['name']],
                        [self::t('reports.r2.pdf.bank'), $bank['name']],
                        [self::t('reports.r2.pdf.validity'), $validity],
                        [self::t('reports.r2.pdf.status'), $bank['status']],
                        [self::t('reports.r2.pdf.generated'), $statement['generated_at']->format('d/m/Y H:i').($generatedBy !== '' ? ' · '.$generatedBy : '')],
                    ],
                    'note' => $portal ? self::t('portal.banks.pdf.note.'.$statement['portal']['visibility'], ['date' => $date]) : self::t('reports.r2.pdf.approved_only', ['date' => $date]),
                ],
                'company' => $statement['company'],
                'kpis' => $kpis,
                'meter' => self::meter($bank['total_minutes'], $f),
                'legend' => self::legend($f, $statement['partial']),
                'notes' => $notes,
                'partial' => $statement['partial'] ? self::t('reports.r2.pdf.partial') : null,
                'financials' => $statement['financials'] === null ? null : [
                    [self::t('reports.r2.pdf.price'), $statement['financials']['price_amount'] !== null ? PdfFormat::money($statement['financials']['price_amount']) : self::t('reports.r2.pdf.no_price')],
                    [self::t('reports.r2.pdf.rate'), $statement['financials']['rate'] !== null ? self::t('reports.r2.pdf.per_hour', ['amount' => PdfFormat::money($statement['financials']['rate'])]) : self::t('reports.r2.pdf.rate_person')],
                    [self::t('reports.r2.pdf.income'), PdfFormat::money($statement['financials']['income'])],
                ],
                'months' => self::months($statement['months']),
                'entries' => self::entries($statement['entries'], $f, self::t($portal ? 'portal.banks.pdf.no_entries' : 'reports.r2.pdf.no_entries')),
            ],
        );
    }

    /**
     * Tramos de la barra en % del ancho, como HourBankMeter: lo que va dentro hasta el total (y lo
     * pendiente dentro, en claro), el hueco restante y el exceso detrás; la marca, en el total.
     *
     * @param  array{in_bank: int, overage: int, pending_in_bank: int, pending_overage: int}  $f
     * @return array{segments: list<array{class: string, width: float}>, mark: float|null}
     */
    public static function meter(int $total, array $f): array
    {
        $scale = max($total + $f['overage'] + $f['pending_overage'], $f['in_bank'] + $f['pending_in_bank'], 1);
        $inside = min($f['in_bank'], $total);
        $pendingInside = min($f['pending_in_bank'], max($total - $inside, 0));
        $width = fn (int $minutes): float => round($minutes / $scale * 100, 3);

        $segments = array_values(array_filter([
            ['class' => 'in', 'width' => $width($inside)],
            ['class' => 'pending', 'width' => $width($pendingInside)],
            ['class' => 'gap', 'width' => $width(max($total - $inside - $pendingInside, 0))],
            ['class' => 'over', 'width' => $width($f['overage'])],
            ['class' => 'pending-over', 'width' => $width($f['pending_overage'])],
        ], fn (array $segment): bool => $segment['width'] > 0));

        return ['segments' => $segments, 'mark' => $total > 0 ? $width($total) : null];
    }

    /**
     * @param  array{in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int}  $f
     * @return list<array{class: string, label: string}>
     */
    private static function legend(array $f, bool $partial): array
    {
        $legend = [['class' => 'in', 'label' => self::t('reports.r2.pdf.in_bank').': '.PdfFormat::minutes($f['in_bank'])]];
        if ($f['pending_in_bank'] > 0) {
            $legend[] = ['class' => 'pending', 'label' => self::t($partial ? 'reports.r2.pdf.legend_pending_partial' : 'reports.r2.pdf.legend_pending').': '.PdfFormat::minutes($f['pending_in_bank'])];
        }
        if ($f['overage'] > 0) {
            $legend[] = ['class' => 'over', 'label' => self::t('reports.r2.pdf.overage').': '.PdfFormat::overage($f['overage'])];
        }
        if ($f['pending_overage'] > 0) {
            $legend[] = ['class' => 'pending-over', 'label' => self::t($partial ? 'reports.r2.pdf.legend_pending_overage_partial' : 'reports.r2.pdf.legend_pending_overage').': '.PdfFormat::overage($f['pending_overage'])];
        }
        $legend[] = ['class' => 'rest', 'label' => self::t('reports.r2.pdf.remaining').': '.PdfFormat::minutes($f['remaining'])];

        return $legend;
    }

    /**
     * @param  list<array{month: string, in_bank: int, overage: int}>  $months
     * @return array<string, mixed>|null
     */
    private static function months(array $months): ?array
    {
        if ($months === []) {
            return null;
        }

        $rows = array_map(fn (array $month): array => [
            ucfirst(PdfFormat::month($month['month'])),
            PdfFormat::minutes($month['in_bank']),
            $month['overage'] > 0 ? PdfTable::cell(PdfFormat::overage($month['overage']), 'overage') : PdfFormat::overage(0),
            PdfFormat::minutes($month['in_bank'] + $month['overage']),
        ], $months);
        $inBank = array_sum(array_column($months, 'in_bank'));
        $overage = array_sum(array_column($months, 'overage'));

        return PdfTable::make(
            [[self::t('reports.r2.pdf.month')], [self::t('reports.r2.pdf.in_bank'), true], [self::t('reports.r2.pdf.overage'), true], [self::t('reports.r2.pdf.total'), true]],
            $rows,
            [self::t('reports.r2.pdf.total'), PdfFormat::minutes($inBank), $overage > 0 ? PdfTable::cell(PdfFormat::overage($overage), 'overage') : PdfFormat::overage(0), PdfFormat::minutes($inBank + $overage)],
        );
    }

    /**
     * @param  list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>  $entries
     * @param  array{in_bank: int, overage: int}  $f
     * @return array<string, mixed>
     */
    private static function entries(array $entries, array $f, string $empty): array
    {
        $rows = array_map(fn (array $entry): array => [
            PdfFormat::date($entry['date']),
            $entry['person'],
            $entry['task'],
            PdfFormat::minutes($entry['in_bank']),
            $entry['overage'] > 0 ? PdfTable::cell(PdfFormat::overage($entry['overage']), 'overage') : PdfFormat::overage(0),
            mb_strlen($entry['description']) > self::MAX_DESCRIPTION ? mb_substr($entry['description'], 0, self::MAX_DESCRIPTION).'…' : $entry['description'],
        ], $entries);

        return PdfTable::make(
            [[self::t('reports.r2.pdf.date')], [self::t('reports.r2.pdf.person')], [self::t('reports.r2.pdf.task')],
                [self::t('reports.r2.pdf.in_bank_short'), true], [self::t('reports.r2.pdf.overage'), true], [self::t('reports.r2.pdf.description')]],
            $rows,
            $rows === [] ? null : [self::t('reports.r2.pdf.total'), '', '', PdfFormat::minutes($f['in_bank']),
                $f['overage'] > 0 ? PdfTable::cell(PdfFormat::overage($f['overage']), 'overage') : PdfFormat::overage(0), ''],
            compact: true,
            empty: $empty,
        );
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        return PdfFormat::text($key, $replace);
    }
}
