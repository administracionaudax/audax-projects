<?php

use App\Domain\Billing\Issuing\BillingDocumentsView;
use Illuminate\Database\Migrations\Migration;

/**
 * Respuesta del propietario del 12/10 (D-449, cambia D-437): el cobro de una factura propia
 * anulada no cuenta en lo cobrado (Resumen), como en Holded. Se rehace la vista `billing_payments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingDocumentsView::dropPayments();
        BillingDocumentsView::createPayments();
    }

    public function down(): void
    {
        // La vista anterior contaba los cobros de las anuladas; no se vuelve a ella (solo es una vista).
    }
};
