<?php

use App\Http\Controllers\Billing\BillingHomeController;
use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Billing\ClientBillingController;
use App\Http\Controllers\Billing\HoldedContactController;
use App\Http\Controllers\Billing\HoldedInvoiceController;
use App\Http\Controllers\Billing\HoldedInvoiceLinkController;
use App\Http\Controllers\Billing\InvoicingReportController;
use App\Http\Controllers\Billing\MovedReportController;
use App\Http\Controllers\Billing\ProjectBillingController;
use App\Http\Controllers\Billing\SoldVsActualController;
use App\Http\Controllers\Reports\Exports\BillingReportController;
use App\Providers\ReportsServiceProvider;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facturación (Fase 12, F1; D-380 a D-399 y D-400 a D-403): lectura de Holded, el informe de
| facturación, «Vendido frente a real» y las horas para facturar. Nombres billing.* (y
| projects.billing y clients.billing en sus áreas).
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', 'collaborator', '2fa'].
| Casi todo detrás del módulo `billing` (apagado, 404; en modo de prueba, solo admins, D-239).
| Importes, facturas, cobros, contactos, datos fiscales y el informe de facturación: view-billing
| (view-financials). «Vendido frente a real»: también quien ve las bolsas, en horas
| (view-sold-vs-actual). Nunca se escribe en Holded.
|
| Fuera de Informes (D-401): los informes con importes de facturación ya no cuelgan de /informes,
| que ven todos los empleados. Las URL antiguas responden con un 301 a las nuevas (con su query,
| así siguen valiendo los enlaces guardados y las exportaciones con ?formato=). Las horas para
| facturar no exigen el módulo (D-402): se ven con ClientPolicy::viewBilling, como antes.
*/

$exports = 'throttle:'.ReportsServiceProvider::EXPORT_LIMITER;

Route::get('facturacion/horas-para-facturar', BillingReportController::class)
    ->middleware($exports)
    ->name('billing.hours');

Route::get('informes/facturacion', MovedReportController::class)
    ->defaults('to', '/facturacion/horas-para-facturar');
Route::get('informes/vendido-frente-a-real', MovedReportController::class)
    ->defaults('to', '/facturacion/vendido-frente-a-real');

Route::middleware('module:billing')->group(function () use ($exports) {
    // /facturacion: el informe (view-billing) o, si solo ve el vendido frente a real, ese.
    Route::get('facturacion', BillingHomeController::class)->name('billing.index');

    Route::get('facturacion/informe', InvoicingReportController::class)
        ->middleware(['can:view-billing', $exports])
        ->name('billing.report');

    Route::get('facturacion/vendido-frente-a-real', SoldVsActualController::class)
        ->middleware(['can:view-sold-vs-actual', $exports])
        ->name('billing.sold-vs-actual');

    Route::get('proyectos/{project}/facturacion', ProjectBillingController::class)
        ->whereNumber('project')
        ->middleware('can:view-sold-vs-actual')
        ->name('projects.billing');

    Route::middleware('can:view-billing')->group(function () {
        Route::get('facturacion/facturas', [HoldedInvoiceController::class, 'index'])->name('billing.invoices.index');
        Route::get('facturacion/facturas/{invoice}', [HoldedInvoiceController::class, 'show'])->whereNumber('invoice')->name('billing.invoices.show');
        Route::get('facturacion/facturas/{invoice}/pdf', [HoldedInvoiceController::class, 'pdf'])
            ->whereNumber('invoice')
            ->middleware('throttle:60,1,billing.invoices.pdf')
            ->name('billing.invoices.pdf');
        Route::post('facturacion/facturas/{invoice}/enlaces', [HoldedInvoiceLinkController::class, 'store'])->whereNumber('invoice')->name('billing.invoices.links.store');
        Route::delete('facturacion/facturas/{invoice}/enlaces/{link}', [HoldedInvoiceLinkController::class, 'destroy'])
            ->whereNumber(['invoice', 'link'])
            ->name('billing.invoices.links.destroy');

        Route::get('facturacion/contactos', [HoldedContactController::class, 'index'])->name('billing.contacts.index');
        Route::put('facturacion/contactos/{contact}', [HoldedContactController::class, 'update'])->whereNumber('contact')->name('billing.contacts.update');

        Route::get('facturacion/ajustes', [BillingSettingsController::class, 'edit'])->name('billing.settings');
        Route::put('facturacion/ajustes', [BillingSettingsController::class, 'update'])->name('billing.settings.update');
        Route::put('facturacion/ajustes/acceso', [BillingSettingsController::class, 'updateAccess'])->name('billing.settings.access');
        Route::post('facturacion/sincronizar', [BillingSettingsController::class, 'sync'])
            ->middleware(['can:sync-holded', 'throttle:6,1,billing.sync'])
            ->name('billing.sync');

        Route::get('clientes/{client}/facturacion', [ClientBillingController::class, 'show'])->whereNumber('client')->name('clients.billing');
        Route::put('clientes/{client}/datos-fiscales', [ClientBillingController::class, 'update'])->whereNumber('client')->name('clients.billing.update');
    });
});
