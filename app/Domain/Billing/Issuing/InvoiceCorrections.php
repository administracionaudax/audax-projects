<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Time\TimeLockService;
use App\Enums\InvoiceRecordKind;
use App\Enums\RectificationKind;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Models\NumberingSeries;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\SifInstallation;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Corregir una factura emitida (D-244; RD 1619/2012, art. 15; PLAN-EMISION §3.2; D-424). Una
 * emitida nunca se edita: solo se rectifica o se anula, siempre con un motivo.
 *
 * - **Anular** (la vía habitual): emite en la serie de rectificativas (CN) una rectificativa por el
 *   total en negativo (las líneas de la original y de sus rectificativas vivas, cambiadas de signo).
 *   La original queda «Anulada» y sus horas se desbloquean para poder facturarlas otra vez.
 * - **Rectificar** (por diferencias): emite una rectificativa solo por la diferencia entre lo emitido
 *   y lo que debería haber sido (unidades o precio de cada línea). La original sigue emitida y sus
 *   horas, bloqueadas.
 * - **Anulación por error** (V-03, solo un admin): para una factura que no debió existir (una de
 *   prueba, un error detectado antes de entregarla): añade un registro de anulación a la cadena y la
 *   deja «Anulada por error». Solo si no se ha cobrado ni tiene rectificativas vivas.
 *
 * La clave de la rectificativa (R1 por defecto, R4 para el resto) queda pendiente de la gestoría (G-2).
 */
final class InvoiceCorrections
{
    public const array CODES = ['R1', 'R2', 'R3', 'R4'];

    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly TimeLockService $locks,
    ) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function cancel(SalesDocument $original, User $by, string $reason, string $code = 'R1'): SalesDocument
    {
        $this->assertCorrectable($original, $by);

        return DB::transaction(function () use ($original, $by, $reason, $code): SalesDocument {
            $original->loadMissing('lines');
            $lines = [];
            foreach ($original->lines as $line) {
                $lines[] = self::negated($line);
            }
            // Lo que ya rectificaron sus rectificativas vivas también se deshace: el neto queda a 0.
            $credits = SalesDocument::query()->where('rectified_document_id', $original->id)->where('status', SalesDocumentStatus::Issued->value)->with('lines')->get();
            foreach ($credits as $credit) {
                foreach ($credit->lines as $line) {
                    $lines[] = self::negated($line);
                }
            }

            $credit = $this->draftCredit($original, $by, RectificationKind::Cancellation, $reason, $code, $lines);

            return $this->issuer->issue($credit, $by);
        });
    }

    /**
     * @param  list<array{line_id: int, quantity: string, unit_price: string}>  $corrections  cómo debería haber sido cada línea
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function rectify(SalesDocument $original, User $by, string $reason, array $corrections, string $code = 'R1'): SalesDocument
    {
        $this->assertCorrectable($original, $by);
        $original->loadMissing('lines');
        $byId = $original->lines->keyBy('id');
        $lines = [];

        foreach ($corrections as $correction) {
            $line = $byId->get($correction['line_id']);
            if ($line === null || ! $line->isItem()) {
                continue;
            }
            $quantity = DocumentTotals::num(bcadd(self::number($correction['quantity']), '0', 4));
            $price = DocumentTotals::num(bcadd(self::number($correction['unit_price']), '0', 4));
            $deltaQuantity = DocumentTotals::num(bcsub($quantity, DocumentTotals::num((string) $line->quantity), 4));
            $deltaPrice = DocumentTotals::num(bcsub($price, DocumentTotals::num((string) $line->unit_price), 4));

            if (bccomp($deltaQuantity, '0', 4) !== 0) {
                $lines[] = [...self::copy($line), 'quantity' => $deltaQuantity];
            }
            if (bccomp($deltaPrice, '0', 4) !== 0) {
                $lines[] = [...self::copy($line), 'quantity' => $quantity, 'unit_price' => $deltaPrice];
            }
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('invoicing.errors.no_difference')]);
        }

        return DB::transaction(fn (): SalesDocument => $this->issuer->issue(
            $this->draftCredit($original, $by, RectificationKind::Differences, $reason, $code, $lines),
            $by,
        ));
    }

    /**
     * Anulación por error (V-03): registro de anulación en la cadena y la factura «Anulada por error».
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function void(SalesDocument $document, User $by, string $reason): SalesDocument
    {
        if (! InvoicingAccess::voids($by)) {
            throw new AuthorizationException(__('invoicing.errors.cannot_void'));
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => __('invoicing.errors.reason_required')]);
        }

        $voided = DB::transaction(function () use ($document, $by, $reason): SalesDocument {
            $series = NumberingSeries::query()->findOrFail($document->series_id);
            $installation = SifInstallation::query()->findOrFail($series->sif_installation_id);
            RecordChain::lock($installation);

            /** @var SalesDocument $locked */
            $locked = SalesDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            foreach (self::voidProblems($locked) as $problem) {
                throw ValidationException::withMessages(['document' => $problem]);
            }

            $issuer = $locked->issuer_snapshot ?? FiscalParties::issuer();
            RecordChain::append($installation, InvoiceRecordKind::Anulacion, $locked, [
                'issuer_tax_id' => (string) ($issuer['tax_id'] ?? ''),
                'invoice_number' => (string) $locked->full_number,
                'issue_date_text' => RecordHasher::date($locked->issue_date),
            ], RecordPayload::anulacion($locked, $installation, array_map(fn (mixed $value): ?string => is_string($value) ? $value : null, $issuer), $reason));

            $locked->forceFill([
                'status' => SalesDocumentStatus::Voided,
                'voided_at' => CarbonImmutable::now(),
                'voided_by' => $by->id,
                'void_reason' => $reason,
            ])->save();

            InvoiceIssuer::releaseHours($locked, $by, $this->locks);

            return $locked;
        });

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($voided)
            ->event('voided')
            ->withProperties(['number' => $voided->full_number, 'reason' => $reason])
            ->log('invoicing.voided');

        return $voided;
    }

    /**
     * Lo que impide anular el registro de una factura (vacío si se puede).
     *
     * @return list<string>
     */
    public static function voidProblems(SalesDocument $document): array
    {
        if ($document->status !== SalesDocumentStatus::Issued) {
            return [__('invoicing.errors.void_not_issued')];
        }
        if (bccomp(DocumentTotals::num((string) $document->paid_total), '0', 2) !== 0) {
            return [__('invoicing.errors.void_paid')];
        }
        if (SalesDocument::query()->where('rectified_document_id', $document->id)->whereIn('status', [SalesDocumentStatus::Issued->value, SalesDocumentStatus::Cancelled->value])->exists()) {
            return [__('invoicing.errors.void_has_credits')];
        }
        if ($document->rectification_kind === RectificationKind::Cancellation) {
            return [__('invoicing.errors.void_cancellation')];
        }

        return [];
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function assertCorrectable(SalesDocument $original, User $by): void
    {
        if (! InvoicingAccess::manages($by)) {
            throw new AuthorizationException(__('invoicing.errors.cannot_issue'));
        }
        if ($original->status !== SalesDocumentStatus::Issued || $original->isCreditNote()) {
            throw ValidationException::withMessages(['document' => __('invoicing.errors.rectified_not_alive')]);
        }
    }

    /**
     * El borrador de la rectificativa: misma instalación (su serie de rectificativas), mismo cliente,
     * hoy (o el día de la original si fuera posterior), sus enlaces heredados y las líneas dadas.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftCredit(SalesDocument $original, User $by, RectificationKind $kind, string $reason, string $code, array $lines): SalesDocument
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => __('invoicing.errors.reason_required')]);
        }
        if (! in_array($code, self::CODES, true)) {
            throw ValidationException::withMessages(['code' => __('invoicing.errors.code')]);
        }

        $series = NumberingSeries::query()->findOrFail($original->series_id);
        $refund = $series->refund_series_id !== null ? NumberingSeries::query()->find($series->refund_series_id) : null;
        if ($refund === null) {
            throw ValidationException::withMessages(['series_id' => __('invoicing.errors.no_refund_series', ['series' => $series->code])]);
        }

        $today = LocalTime::today();
        $credit = new SalesDocument;
        $credit->fill([
            'uuid' => (string) Str::uuid(),
            'type' => SalesDocumentType::CreditNote,
            'is_test' => $original->is_test,
            'series_id' => $refund->id,
            'issue_date' => max($today->toDateString(), $original->issue_date->toDateString()),
            'client_id' => $original->client_id,
            'client_name' => $original->client_name,
            'language' => $original->language,
            'withholding_rate_id' => $original->withholding_rate_id,
            'withholding_rate' => $original->withholding_rate,
            'rectified_document_id' => $original->id,
            'rectification_kind' => $kind,
            'rectification_reason' => $reason,
            'rectification_code' => $code,
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ]);
        $credit->save();

        foreach ($lines as $position => $line) {
            $model = new SalesDocumentLine(['sales_document_id' => $credit->id]);
            $model->forceFill([...$line, 'position' => $position + 1])->save();
        }

        foreach ($original->links()->get() as $link) {
            SalesDocumentLink::query()->create(['sales_document_id' => $credit->id, 'project_id' => $link->project_id, 'hour_bank_id' => $link->hour_bank_id, 'method' => 'rectified', 'created_by' => $by->id]);
        }

        DraftWriter::refreshTotals($credit);

        return $credit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function copy(SalesDocumentLine $line): array
    {
        return [
            'kind' => $line->kind,
            'service_id' => $line->service_id,
            'name' => $line->name,
            'service_code' => $line->service_code,
            'description' => $line->description,
            'quantity' => (string) $line->quantity,
            'unit' => $line->unit,
            'unit_price' => (string) $line->unit_price,
            'discount_pct' => (string) $line->discount_pct,
            'tax_rate_id' => $line->tax_rate_id,
            'tax_rate' => $line->tax_rate,
            'operation_type' => $line->operation_type,
            'project_id' => $line->project_id,
            'hour_bank_id' => $line->hour_bank_id,
            'origin' => $line->origin,
            'minutes' => $line->minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function negated(SalesDocumentLine $line): array
    {
        return [...self::copy($line), 'quantity' => $line->isItem() ? bcmul(DocumentTotals::num((string) $line->quantity), '-1', 4) : '0'];
    }

    /**
     * @return numeric-string
     */
    private static function number(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }
}
