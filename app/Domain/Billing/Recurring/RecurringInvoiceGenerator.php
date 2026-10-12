<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Issuing\DraftWriter;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\RecurringInvoiceRun;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Genera las facturas de las recurrentes (PLAN-EMISION §3.1, H-057 a H-061; E3):
 *
 * - **La tarea diaria** (`app:billing-generate-recurring`, runDue): cada recurrente activa cuya
 *   próxima fecha ya ha llegado genera la factura de ese periodo (y, si un día no corrió, se pone al
 *   día, como mucho CATCH_UP periodos por recurrente). **Solo con los módulos `billing` e
 *   `invoicing` encendidos de verdad** (D-239): en modo de prueba no genera nada.
 * - **«Generar ahora»** (generateNow): la del próximo periodo, a mano, también en modo de prueba.
 *
 * Cada periodo, en una transacción con la recurrente bloqueada: si ya tiene su fila en el historial
 * (índice único por recurrente y periodo) no hace nada; si está omitido, lo anota como omitido; si
 * no, crea el borrador (cliente, líneas con las palabras dinámicas del periodo, proyecto, serie,
 * forma de pago y vencimiento) y avanza la próxima fecha. **Nunca duplica**.
 *
 * Por defecto la factura queda en **borrador** (como hace hoy Audax en Holded: alguien la revisa y
 * la emite). En modo «Emitir», después de crearla la emite en nombre de quien eligió ese modo
 * (`issue_as`, con manage-billing) y, si se pidió, la envía a los emails de facturación del cliente.
 * Si no se puede (falta un dato, la serie aún no ha empezado, el cliente no tiene email), se queda
 * en borrador con el motivo, que sale en «Requiere atención» del Resumen.
 */
final class RecurringInvoiceGenerator
{
    /** Periodos atrasados que se generan como mucho de una vez por recurrente. */
    public const int CATCH_UP = 3;

    public function __construct(
        private readonly DraftWriter $writer,
        private readonly AutoIssue $auto,
    ) {}

    /** ¿Genera la tarea diaria? Solo con la emisión encendida de verdad (no en modo de prueba). */
    public static function active(): bool
    {
        return AppModules::enabled(AppModule::Billing) && AppModules::enabled(AppModule::Invoicing);
    }

    /**
     * La tarea diaria.
     *
     * @return array{generated: int, skipped: int, failed: int}
     */
    public function runDue(?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

        $due = RecurringInvoice::query()->running()
            ->whereDate('next_run_on', '<=', $today->toDateString())
            ->orderBy('next_run_on')->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            for ($round = 0; $round < self::CATCH_UP; $round++) {
                $recurring = RecurringInvoice::query()->whereKey($id)->first();
                if ($recurring === null || $recurring->next_run_on === null || $recurring->paused_at !== null
                    || $recurring->next_run_on->toDateString() > $today->toDateString()) {
                    break;
                }

                try {
                    $run = $this->period($recurring, $recurring->next_run_on, RecurringInvoiceRun::SCHEDULE, null, $today);
                } catch (Throwable $error) {
                    $this->failed($recurring, $error);
                    $result['failed']++;

                    break;
                }

                if ($run === null) {
                    break;
                }
                $result[$run->status === RecurringInvoiceRun::SKIPPED ? 'skipped' : 'generated']++;
            }
        }

        return $result;
    }

    /**
     * «Generar ahora»: la factura del próximo periodo, ya (con la fecha de hoy). Si ese periodo
     * está omitido, genera el siguiente que no lo esté.
     *
     * @throws ValidationException
     */
    public function generateNow(RecurringInvoice $recurring, User $by): RecurringInvoiceRun
    {
        $today = LocalTime::today();

        for ($round = 0; $round < 24; $round++) {
            $recurring->refresh();
            if ($recurring->isFinished()) {
                throw ValidationException::withMessages(['recurring' => __('invoicing_work.recurring.errors.finished')]);
            }

            /** @var CarbonImmutable $period */
            $period = $recurring->next_run_on;
            $run = $this->period($recurring, $period, RecurringInvoiceRun::MANUAL, $by, $today);
            if ($run === null) {
                throw ValidationException::withMessages(['recurring' => __('invoicing_work.recurring.errors.busy')]);
            }
            if ($run->status === RecurringInvoiceRun::GENERATED) {
                return $run;
            }
        }

        throw ValidationException::withMessages(['recurring' => __('invoicing_work.recurring.errors.finished')]);
    }

    /**
     * Un periodo: lo genera o lo anota como omitido. Null si ya estaba hecho (otro proceso se
     * adelantó): nunca duplica.
     */
    private function period(RecurringInvoice $recurring, CarbonImmutable $period, string $trigger, ?User $by, CarbonImmutable $today): ?RecurringInvoiceRun
    {
        try {
            $run = DB::transaction(function () use ($recurring, $period, $trigger, $by, $today): ?RecurringInvoiceRun {
                /** @var RecurringInvoice|null $locked */
                $locked = RecurringInvoice::query()->whereKey($recurring->id)->lockForUpdate()->first();
                if ($locked === null || $locked->next_run_on === null || $locked->next_run_on->toDateString() !== $period->toDateString()) {
                    return null;
                }
                if (RecurringInvoiceRun::query()->where('recurring_invoice_id', $locked->id)->whereDate('period_on', $period->toDateString())->exists()) {
                    return null;
                }

                if ($locked->isSkipped($period)) {
                    $run = RecurringInvoiceRun::query()->create([
                        'recurring_invoice_id' => $locked->id,
                        'period_on' => $period->toDateString(),
                        'status' => RecurringInvoiceRun::SKIPPED,
                        'trigger' => $trigger,
                        'created_by' => $by?->id,
                    ]);
                    $this->advance($locked, $period, null);

                    return $run;
                }

                $document = $this->draft($locked, $period, $by, $today);
                $run = RecurringInvoiceRun::query()->create([
                    'recurring_invoice_id' => $locked->id,
                    'period_on' => $period->toDateString(),
                    'status' => RecurringInvoiceRun::GENERATED,
                    'trigger' => $trigger,
                    'sales_document_id' => $document->id,
                    'created_by' => $by?->id,
                ]);
                $this->advance($locked, $period, $document);

                activity('invoicing')
                    ->causedBy($by)
                    ->performedOn($document)
                    ->event('recurring_generated')
                    ->withProperties(['recurring' => $locked->id, 'period' => $period->toDateString(), 'trigger' => $trigger])
                    ->log('invoicing.recurring_generated');

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            // Otro proceso generó el mismo periodo a la vez: el índice único lo impide.
            return null;
        }

        if ($run !== null && $run->status === RecurringInvoiceRun::GENERATED && $recurring->mode === RecurringInvoice::MODE_ISSUE) {
            $this->issueAndSend($recurring->refresh(), $run, $by);
        }

        return $run;
    }

    /** El borrador del periodo, con las palabras dinámicas cambiadas. */
    private function draft(RecurringInvoice $recurring, CarbonImmutable $period, ?User $by, CarbonImmutable $today): SalesDocument
    {
        $recurring->loadMissing(['lines', 'client.billingProfile']);
        $language = ($recurring->client->billingProfile->language ?? 'es') === 'en' ? 'en' : 'es';
        $words = fn (?string $text): ?string => DynamicWords::apply($text, $period, $language);
        $author = $by ?? User::query()->find($recurring->issue_as ?? $recurring->updated_by ?? $recurring->created_by);

        $document = $this->writer->save(null, [
            'client_id' => $recurring->client_id,
            'series_id' => $recurring->series_id,
            'issue_date' => $today->toDateString(),
            'due_date' => $recurring->due_days === null ? null : $today->addDays($recurring->due_days)->toDateString(),
            'payment_method_id' => $recurring->payment_method_id,
            'withholding_rate_id' => $recurring->withholding_rate_id,
            'body' => $words($recurring->body),
            'internal_note' => $words($recurring->internal_note),
            'customer_reference' => $recurring->customer_reference,
            'project_id' => $recurring->project_id,
            'hour_bank_id' => $recurring->project_id === null ? null : $recurring->hour_bank_id,
            'lines' => array_values($recurring->lines->map(fn (RecurringInvoiceLine $line): array => [
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'description' => $words($line->description),
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit->value,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax_rate_id' => $line->tax_rate_id,
            ])->all()),
        ], $author ?? new User);

        $document->forceFill(['recurring_invoice_id' => $recurring->id, 'recurring_period_on' => $period->toDateString()])->save();

        return $document;
    }

    /** Avanza a la siguiente fecha (o la da por acabada) y apunta la última factura. */
    private function advance(RecurringInvoice $recurring, CarbonImmutable $period, ?SalesDocument $document): void
    {
        $generated = $recurring->occurrences_count + ($document !== null ? 1 : 0);
        $next = RecurringSchedule::next($recurring, $period, $generated);

        $recurring->forceFill([
            'occurrences_count' => $generated,
            'next_run_on' => $next?->toDateString(),
            'finished_at' => $next === null ? now() : null,
            'last_document_id' => $document->id ?? $recurring->last_document_id,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    /**
     * Modo «Emitir»: la emite en nombre de quien lo eligió (o de quien pulsó «Generar ahora») y,
     * si se pidió, la envía. Lo que falle deja la factura en borrador con el motivo.
     */
    private function issueAndSend(RecurringInvoice $recurring, RecurringInvoiceRun $run, ?User $by): void
    {
        /** @var SalesDocument $document */
        $document = SalesDocument::query()->findOrFail($run->sales_document_id);
        $issuer = $by ?? ($recurring->issue_as !== null ? User::query()->find($recurring->issue_as) : null);

        $issued = $this->auto->issue($document, $issuer);
        if (is_string($issued)) {
            $run->forceFill(['error' => $issued])->save();

            return;
        }

        if ($recurring->auto_send && $issuer !== null && ($error = $this->auto->send($issued, $issuer)) !== null) {
            $run->forceFill(['error' => $error])->save();
        }
    }

    private function failed(RecurringInvoice $recurring, Throwable $error): void
    {
        Log::warning('Recurrente sin generar', ['recurring' => $recurring->id, 'error' => $error->getMessage()]);
        RecurringInvoice::query()->whereKey($recurring->id)->update([
            'last_error' => mb_substr(AutoIssue::reason($error), 0, 500),
            'last_error_at' => now(),
        ]);
    }
}
