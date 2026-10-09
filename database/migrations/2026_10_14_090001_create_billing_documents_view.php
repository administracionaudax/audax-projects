<?php

use App\Domain\Billing\Issuing\BillingDocumentsView;
use App\Domain\Billing\Issuing\InvoicingDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Emisión propia, E1 (PLAN-EMISION §4.6; D-427): las vistas que unen las facturas de Holded y las
 * propias (`billing_documents`, `billing_documents_all`, `billing_document_lines` y
 * `billing_document_links`) y lo que hace falta de partida: impuestos, formas de pago, las dos
 * instalaciones del sistema de facturación y las series F, CN, PRU y PRUCN (D-419 y D-423).
 */
return new class extends Migration
{
    public function up(): void
    {
        BillingDocumentsView::create();
        InvoicingDefaults::install();
    }

    public function down(): void
    {
        BillingDocumentsView::drop();
    }
};
