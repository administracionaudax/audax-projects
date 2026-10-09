<?php

namespace App\Jobs;

use App\Domain\Billing\Issuing\InvoicePdf;
use App\Models\SalesDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * El PDF de una factura recién emitida (PLAN-EMISION §5.1; D-426): se genera tras el commit, con las
 * copias congeladas, y se archiva con su SHA-256 una sola vez. Si Gotenberg está caído, la factura
 * existe igual: se reintenta y, si no, se genera la primera vez que alguien abre el PDF.
 */
class GenerateInvoicePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int $documentId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(InvoicePdf $pdf): void
    {
        $document = SalesDocument::query()->find($this->documentId);

        if ($document === null || $document->isDraft() || $document->pdf_sha256 !== null) {
            return;
        }

        $pdf->archive($document);
    }
}
