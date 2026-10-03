<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Export\EntryRows;
use App\Domain\Reports\Export\KeysetPages;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * PDF de un listado de entradas de horas (exportación de horas y pestaña Horas del proyecto):
 * cifras (horas, dentro de bolsa, exceso, facturables y, con datos económicos, el ingreso) y las
 * primeras PDF_ENTRIES entradas en una tabla compacta; el resto, en Excel o CSV.
 */
trait EntryListPdf
{
    /** Entradas en el PDF (más, solo en Excel o CSV). */
    public const int PDF_ENTRIES = 1500;

    /**
     * @param  Builder<TimeEntry>  $entries
     * @return array{kpis: list<array{label: string, value: string, detail: string|null}>, entries: array<string, mixed>, more: int}
     */
    protected function entryList(Builder $entries, bool $financials, RevenueCalculator $revenue): array
    {
        $totals = (clone $entries)->toBase()->selectRaw('COUNT(*) as entries, COALESCE(SUM(time_entries.minutes), 0) as minutes,
            COALESCE(SUM(time_entries.overage_minutes), 0) as overage,
            COALESCE(SUM(CASE WHEN time_entries.hour_bank_id IS NOT NULL THEN time_entries.minutes - time_entries.overage_minutes ELSE 0 END), 0) as in_bank,
            COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END), 0) as billable')->first();
        $count = (int) ($totals->entries ?? 0);

        $kpis = [
            ['label' => self::t('report_pdf.metrics.logged.label'), 'value' => PdfFormat::minutes((int) ($totals->minutes ?? 0)), 'detail' => self::t('report_pdf.billing.entries', ['count' => PdfFormat::number($count)])],
            ['label' => self::t('report_pdf.metrics.billable.label'), 'value' => PdfFormat::minutes((int) ($totals->billable ?? 0)), 'detail' => null],
            ['label' => self::t('report_pdf.metrics.in_bank.label'), 'value' => PdfFormat::minutes((int) ($totals->in_bank ?? 0)), 'detail' => null],
            ['label' => self::t('report_pdf.metrics.overage.label'), 'value' => PdfFormat::overage((int) ($totals->overage ?? 0)), 'detail' => null],
        ];
        if ($financials) {
            $kpis[] = ['label' => self::t('report_pdf.metrics.income.label'), 'value' => PdfFormat::money($revenue->compute(clone $entries)['all']['income'] ?? '0.00'), 'detail' => null];
        }

        $columns = [[self::t('report_pdf.columns.date')], [self::t('report_pdf.columns.person')], [self::t('report_pdf.columns.project')],
            [self::t('report_pdf.columns.task')], [self::t('report_pdf.columns.description')], [self::t('report_pdf.columns.hours'), true],
            [self::t('report_pdf.columns.overage'), true], [self::t('report_pdf.columns.status')]];
        $rows = [];
        /** @var array<string, string> $statuses */
        $statuses = [];

        foreach (KeysetPages::byDateAndId(EntryRows::flat($entries), 500) as $row) {
            if (count($rows) === self::PDF_ENTRIES) {
                break;
            }

            $overage = (int) $row->overage_minutes;
            $status = (string) $row->status;
            $rows[] = [
                PdfFormat::date(substr((string) $row->date, 0, 10)),
                (string) $row->person_name,
                $row->project_code.($row->bank_name !== null && $row->hour_bank_id !== null ? ' · '.$row->bank_name : ''),
                (string) $row->task_title,
                mb_strimwidth((string) $row->description, 0, 300, '…'),
                PdfFormat::minutes((int) $row->minutes),
                $overage > 0 ? PdfTable::cell(PdfFormat::overage($overage), 'overage') : '',
                ($statuses[$status] ??= TimeEntryStatus::from($status)->label()).((bool) $row->is_billable ? '' : ' · '.self::t('report_pdf.not_billable')),
            ];
        }

        return [
            'kpis' => $kpis,
            'entries' => PdfTable::make($columns, $rows, $rows === [] ? null : [self::t('report_pdf.total'), '', '', '', '', PdfFormat::minutes((int) ($totals->minutes ?? 0)),
                PdfFormat::overage((int) ($totals->overage ?? 0)), ''], compact: true, empty: self::t('report_pdf.no_hours')),
            'more' => max($count - count($rows), 0),
        ];
    }
}
