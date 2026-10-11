<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Portal\PortalScope;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\SalesDocumentStatus;
use App\Models\ClientBillingProfile;
use App\Models\SalesDocument;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las facturas propias del cliente en el portal (PLAN-EMISION §3.5, H-097 y H-100; E2, D-443) y lo
 * que se le enseña de cada una (también en la página del enlace firmado):
 * - Solo las de **su** cliente, emitidas o anuladas con su rectificativa (y las rectificativas).
 *   **Nunca** borradores, anuladas por error (no debieron existir), de la serie de pruebas ni de
 *   otro cliente.
 * - Número, fechas, total, lo pendiente, su estado de cobro, la forma de pago y el PDF archivado.
 *   Nunca la nota interna, los proyectos, quién la hizo ni nada de la plantilla.
 * - Se enciende por cliente («Facturas en el portal», ficha de facturación; apagado por defecto) y
 *   solo con la emisión encendida de verdad: en modo de prueba (D-239) el cliente no ve nada.
 */
final class PortalInvoices
{
    /** ¿Ve sus facturas este cliente? */
    public static function enabledFor(PortalScope $scope): bool
    {
        return AppModules::enabled(AppModule::Billing)
            && AppModules::enabled(AppModule::Invoicing)
            && ClientBillingProfile::query()->where('client_id', $scope->client->id)->where('portal_invoices', true)->exists();
    }

    /**
     * @return Builder<SalesDocument>
     */
    public static function query(PortalScope $scope): Builder
    {
        return SalesDocument::query()
            ->where('client_id', $scope->client->id)
            ->where('is_test', false)
            ->whereIn('status', [SalesDocumentStatus::Issued->value, SalesDocumentStatus::Cancelled->value]);
    }

    /**
     * Lo que ve el cliente de una factura (sin nada interno).
     *
     * @return array<string, mixed>
     */
    public static function summary(SalesDocument $document, string $language = 'es'): array
    {
        $pending = $document->pendingAmount();

        return [
            'id' => $document->id,
            'number' => $document->full_number,
            'type' => $document->type->value,
            'status' => $document->status->value,
            'collection' => self::collection($document),
            'is_test' => $document->is_test,
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'subtotal' => (string) $document->subtotal,
            'tax_total' => (string) $document->tax_total,
            'total' => (string) $document->total,
            'paid_total' => (string) $document->paid_total,
            'pending' => $pending,
            'payment_text' => $document->payment_text,
            'issuer' => $document->issuer_snapshot['trade_name'] ?? $document->issuer_snapshot['legal_name'] ?? null,
            'client' => $document->client_snapshot['legal_name'] ?? $document->client_name,
            'language' => $language,
            'today' => LocalTime::todayString(),
        ];
    }

    /**
     * El estado de cobro, con las claves de `billing_documents` (D-386): paid, partial, overdue,
     * unpaid o cancelled (una rectificativa, como cobrada).
     */
    public static function collection(SalesDocument $document): string
    {
        if (in_array($document->status, [SalesDocumentStatus::Cancelled, SalesDocumentStatus::Voided], true)) {
            return 'cancelled';
        }
        if ($document->status === SalesDocumentStatus::Draft) {
            return 'draft';
        }
        if ($document->isCreditNote() || bccomp($document->pendingAmount(), '0', 2) <= 0) {
            return 'paid';
        }
        if ($document->due_date !== null && $document->due_date->toDateString() < LocalTime::todayString()) {
            return 'overdue';
        }

        return bccomp(DocumentTotals::num((string) $document->paid_total), '0', 2) > 0 ? 'partial' : 'unpaid';
    }

    /** El PDF archivado (generándolo si aún no está), para ver o descargar. */
    public static function pdfResponse(SalesDocument $document, InvoicePdf $pdf, bool $download): Response
    {
        $content = $pdf->archive($document);
        $document->refresh();
        $extension = $document->pdf_path !== null ? pathinfo($document->pdf_path, PATHINFO_EXTENSION) : $pdf->extension();

        return response($content, 200, [
            'Content-Type' => $extension === 'pdf' ? 'application/pdf' : 'text/html; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition($download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $document->pdfFilename($extension)),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
