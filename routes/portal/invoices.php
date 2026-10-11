<?php

use App\Http\Controllers\Portal\Invoices\PortalInvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal · facturas (PLAN-EMISION §3.5, E2; D-443): las facturas propias emitidas del cliente, su
| estado de cobro, el PDF y los adjuntos visibles. Nombres portal.invoices.*.
|--------------------------------------------------------------------------
| El grupo (routes/web.php) añade /portal, el nombre portal. y los middleware auth, active y portal.
| Todo sale de PortalScope y PortalInvoices: una factura de otro cliente, un borrador, una anulada
| por error o de la serie de pruebas dan 404; con «Facturas en el portal» apagado, también.
*/

Route::get('facturas', [PortalInvoiceController::class, 'index'])->name('invoices.index');
Route::get('facturas/{document}/pdf', [PortalInvoiceController::class, 'pdf'])
    ->whereNumber('document')
    ->middleware('throttle:30,1,portal.invoices.pdf')
    ->name('invoices.pdf');
Route::get('facturas/{document}/adjuntos/{attachment}', [PortalInvoiceController::class, 'attachment'])
    ->whereNumber(['document', 'attachment'])
    ->middleware('throttle:30,1,portal.invoices.attachment')
    ->name('invoices.attachment');
