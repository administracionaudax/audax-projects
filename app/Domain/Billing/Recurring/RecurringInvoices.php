<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Issuing\DocumentTotals;
use App\Enums\RecurringPeriodicity;
use App\Enums\ServiceUnit;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crear, cambiar, pausar, reanudar, omitir un periodo, marcar como revisada y borrar una factura
 * recurrente (PLAN-EMISION §3.1 y §6.2; E3). Todo en la auditoría (`invoicing`).
 *
 * - Al crearla o cambiar su calendario, la próxima fecha es la primera desde el inicio (o desde
 *   hoy, si el inicio ya pasó y ya ha generado alguna): nunca se generan de golpe los periodos
 *   pasados.
 * - Al **reanudar**, la próxima es la primera a partir de hoy: lo que tocó durante la pausa no se
 *   genera (se puede «Generar ahora» si hace falta).
 * - **Omitir** un periodo lo anota; deshacerlo, lo quita. Un periodo omitido no gasta una vez.
 * - **Borrar** solo una que aún no ha generado nada; si ya tiene facturas, se pausa (su historial
 *   es la cadena de documentos).
 *
 * @phpstan-type RecurringLine array{kind: string, service_id?: int|null, description?: string|null, quantity?: string|null, unit?: string|null, unit_price?: string|null, discount_pct?: string|null, tax_rate_id?: int|null}
 * @phpstan-type RecurringData array{name: string, client_id: int, project_id?: int|null, hour_bank_id?: int|null, periodicity: string, interval_months?: int|null, day_of_month: int, start_date: string, end_date?: string|null, max_occurrences?: int|null, mode: string, auto_send?: bool, series_id?: int|null, payment_method_id?: int|null, due_days?: int|null, withholding_rate_id?: int|null, body?: string|null, internal_note?: string|null, customer_reference?: string|null, lines: list<RecurringLine>}
 */
final class RecurringInvoices
{
    /**
     * @param  RecurringData  $data
     */
    public function save(?RecurringInvoice $recurring, array $data, User $by, bool $paused = false): RecurringInvoice
    {
        return DB::transaction(function () use ($recurring, $data, $by, $paused): RecurringInvoice {
            $creating = $recurring === null;
            $periodicity = RecurringPeriodicity::from($data['periodicity']);
            $interval = $periodicity->months() ?? max(1, min(24, (int) ($data['interval_months'] ?? 1)));
            $mode = $data['mode'] === RecurringInvoice::MODE_ISSUE ? RecurringInvoice::MODE_ISSUE : RecurringInvoice::MODE_DRAFT;

            $recurring ??= new RecurringInvoice(['created_by' => $by->id]);
            $before = $creating ? null : [$recurring->start_date->toDateString(), $recurring->interval_months, $recurring->day_of_month,
                $recurring->end_date?->toDateString(), $recurring->max_occurrences];

            $recurring->fill([
                'name' => trim($data['name']),
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'hour_bank_id' => isset($data['project_id']) ? ($data['hour_bank_id'] ?? null) : null,
                'periodicity' => $periodicity,
                'interval_months' => $interval,
                'day_of_month' => max(1, min(31, $data['day_of_month'])),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'max_occurrences' => $data['max_occurrences'] ?? null,
                'mode' => $mode,
                'auto_send' => $mode === RecurringInvoice::MODE_ISSUE && ($data['auto_send'] ?? false),
                // Quien elige «Emitir» es en cuyo nombre se emitirá (con manage-billing).
                'issue_as' => $mode === RecurringInvoice::MODE_ISSUE ? $by->id : null,
                'series_id' => $data['series_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'due_days' => $data['due_days'] ?? null,
                'withholding_rate_id' => $data['withholding_rate_id'] ?? null,
                'body' => self::text($data['body'] ?? null),
                'internal_note' => self::text($data['internal_note'] ?? null),
                'customer_reference' => self::text($data['customer_reference'] ?? null),
                'updated_by' => $by->id,
            ]);
            if ($paused && $creating) {
                $recurring->forceFill(['paused_at' => now(), 'paused_by' => $by->id]);
            }
            $recurring->save();

            $after = [$recurring->start_date->toDateString(), $recurring->interval_months, $recurring->day_of_month,
                $recurring->end_date?->toDateString(), $recurring->max_occurrences];
            if ($creating || $before !== $after) {
                $this->reschedule($recurring);
            }

            $this->writeLines($recurring, $data['lines']);

            activity('invoicing')
                ->causedBy($by)
                ->performedOn($recurring)
                ->event($creating ? 'recurring_created' : 'recurring_updated')
                ->withProperties(['name' => $recurring->name, 'mode' => $recurring->mode, 'periodicity' => $recurring->periodicity->value, 'next' => $recurring->next_run_on?->toDateString()])
                ->log($creating ? 'invoicing.recurring_created' : 'invoicing.recurring_updated');

            return $recurring;
        });
    }

    public function pause(RecurringInvoice $recurring, User $by): void
    {
        if ($recurring->isPaused() || $recurring->isFinished()) {
            return;
        }

        $recurring->forceFill(['paused_at' => now(), 'paused_by' => $by->id, 'updated_by' => $by->id])->save();
        $this->log($recurring, $by, 'recurring_paused');
    }

    /** Reanudar: la próxima es la primera a partir de hoy (lo de la pausa no se genera). */
    public function resume(RecurringInvoice $recurring, User $by): void
    {
        if (! $recurring->isPaused()) {
            return;
        }

        $recurring->forceFill(['paused_at' => null, 'paused_by' => null, 'updated_by' => $by->id]);
        $today = LocalTime::today();
        if ($recurring->next_run_on !== null && $recurring->next_run_on->toDateString() < $today->toDateString()) {
            $next = RecurringSchedule::first($recurring, $today);
            $recurring->forceFill(['next_run_on' => $next?->toDateString(), 'finished_at' => $next === null ? now() : null]);
        }
        $recurring->save();
        $this->log($recurring, $by, 'recurring_resumed', ['next' => $recurring->next_run_on?->toDateString()]);
    }

    /**
     * «Omitir este periodo» (o deshacerlo): solo un periodo que aún no se ha generado.
     *
     * @throws ValidationException
     */
    public function skip(RecurringInvoice $recurring, CarbonImmutable $period, bool $skip, User $by): void
    {
        $dates = $recurring->skipped_dates ?? [];
        $date = $period->toDateString();
        $upcoming = array_column(RecurringSchedule::upcoming($recurring, 24), 'date');

        if ($skip && ! in_array($date, $upcoming, true)) {
            throw ValidationException::withMessages(['period' => __('invoicing_work.recurring.errors.not_upcoming')]);
        }

        $dates = $skip ? array_values(array_unique([...$dates, $date])) : array_values(array_diff($dates, [$date]));
        sort($dates);
        $recurring->forceFill(['skipped_dates' => $dates === [] ? null : $dates, 'updated_by' => $by->id])->save();
        $this->log($recurring, $by, $skip ? 'recurring_skipped' : 'recurring_unskipped', ['period' => $date]);
    }

    /** Las importadas de Holded se revisan una a una antes de activarlas («Revisada»). */
    public function review(RecurringInvoice $recurring, User $by): void
    {
        $recurring->forceFill(['reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
        $this->log($recurring, $by, 'recurring_reviewed');
    }

    /**
     * @throws ValidationException
     */
    public function delete(RecurringInvoice $recurring, User $by): void
    {
        if ($recurring->runs()->where('status', 'generated')->exists()) {
            throw ValidationException::withMessages(['recurring' => __('invoicing_work.recurring.errors.has_documents')]);
        }

        $this->log($recurring, $by, 'recurring_deleted', ['name' => $recurring->name]);
        $recurring->delete();
    }

    /**
     * Los datos para crear una recurrente desde una factura («Hacer recurrente», H-033): su cliente,
     * proyecto, líneas y textos; mensual desde el mes que viene, el mismo día.
     *
     * @return array<string, mixed>
     */
    public static function fromDocument(SalesDocument|HoldedInvoice $source): array
    {
        $today = LocalTime::today();
        $start = $today->addMonthNoOverflow()->startOfMonth()->setDay(min($today->day, 28));

        if ($source instanceof HoldedInvoice) {
            $source->loadMissing(['lines', 'links']);
            $link = $source->links->first();

            return [
                'name' => trim(($source->contact_name ?? '').' · '.($source->lines->first()->name ?? '')),
                'client_id' => $source->client_id,
                'project_id' => $link?->project_id,
                'hour_bank_id' => $link?->hour_bank_id,
                'start_date' => $start->toDateString(),
                'day_of_month' => $start->day,
                'body' => $source->notes,
                'lines' => array_values($source->lines->map(fn (HoldedInvoiceLine $line): array => [
                    'kind' => 'item',
                    'service_id' => null,
                    'description' => trim(implode("\n", array_filter([$line->name, $line->description]))),
                    'quantity' => (string) $line->units,
                    'unit' => 'unit',
                    'unit_price' => (string) $line->unit_price,
                    'discount_pct' => (string) $line->discount_pct,
                    'tax_rate_id' => null,
                ])->all()),
            ];
        }

        $source->loadMissing(['lines', 'links']);
        $link = $source->links->first();

        return [
            'name' => trim($source->client_name.' · '.($source->lines->first()->name ?? $source->lines->first()->description ?? '')),
            'client_id' => $source->client_id,
            'project_id' => $link?->project_id,
            'hour_bank_id' => $link?->hour_bank_id,
            'series_id' => $source->series_id,
            'payment_method_id' => $source->payment_method_id,
            'withholding_rate_id' => $source->withholding_rate_id,
            'start_date' => $start->toDateString(),
            'day_of_month' => $start->day,
            'body' => $source->body,
            'internal_note' => $source->internal_note,
            'customer_reference' => $source->customer_reference,
            'lines' => array_values($source->lines->map(fn (SalesDocumentLine $line): array => [
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit->value,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax_rate_id' => $line->tax_rate_id,
            ])->all()),
        ];
    }

    /** La base y el total (sin IVA) de una recurrente, para el listado. */
    public static function subtotal(RecurringInvoice $recurring): string
    {
        $total = '0';
        foreach ($recurring->lines as $line) {
            if ($line->isItem()) {
                $total = bcadd($total, DocumentTotals::lineBase((string) $line->quantity, (string) $line->unit_price, (string) $line->discount_pct), 2);
            }
        }

        return bcadd($total, '0', 2);
    }

    /** La próxima fecha desde el inicio (o desde hoy si ya ha generado alguna y el inicio pasó). */
    private function reschedule(RecurringInvoice $recurring): void
    {
        $today = LocalTime::today();
        $from = $recurring->occurrences_count > 0 && $recurring->start_date->toDateString() < $today->toDateString() ? $today : $recurring->start_date;
        $next = RecurringSchedule::first($recurring, $from);

        $recurring->forceFill(['next_run_on' => $next?->toDateString(), 'finished_at' => $next === null ? now() : null])->save();
    }

    /**
     * @param  list<RecurringLine>  $lines
     */
    private function writeLines(RecurringInvoice $recurring, array $lines): void
    {
        $recurring->lines()->delete();
        foreach ($lines as $position => $line) {
            $item = $line['kind'] === 'item';
            RecurringInvoiceLine::query()->create([
                'recurring_invoice_id' => $recurring->id,
                'position' => $position + 1,
                'kind' => $item ? 'item' : 'text',
                'service_id' => $item ? ($line['service_id'] ?? null) : null,
                'description' => self::text($line['description'] ?? null),
                'quantity' => $item ? self::decimal($line['quantity'] ?? '1', 4) : '0',
                'unit' => $item && in_array($line['unit'] ?? null, ServiceUnit::values(), true) ? $line['unit'] : ServiceUnit::Unit->value,
                'unit_price' => $item ? self::decimal($line['unit_price'] ?? '0', 4) : '0',
                'discount_pct' => $item ? self::decimal($line['discount_pct'] ?? '0', 2) : '0',
                'tax_rate_id' => $item ? ($line['tax_rate_id'] ?? null) : null,
            ]);
        }
        $recurring->unsetRelation('lines');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(RecurringInvoice $recurring, User $by, string $event, array $properties = []): void
    {
        activity('invoicing')
            ->causedBy($by)
            ->performedOn($recurring)
            ->event($event)
            ->withProperties(['name' => $recurring->name, ...$properties])
            ->log('invoicing.'.$event);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function decimal(string|int|float|null $value, int $scale): string
    {
        $string = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;

        return bcadd(DocumentTotals::num($string), '0', $scale);
    }
}
