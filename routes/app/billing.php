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
| Facturación (Fase 12, F1; D-380 a D-399, D-400 a D-404 y D-405 a D-410): lectura de Holded, el
| informe de ventas, «Vendido frente a real», lo pendiente de facturar y lo que queda por revisar.
| Nombres billing.* (y projects.billing y clients.billing en sus áreas).
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', 'collaborator', '2fa'].
| Casi todo detrás del módulo `billing` (apagado, 404; en modo de prueba, solo admins, D-239).
| Importes, facturas, cobros, contactos, datos fiscales y el informe de ventas: view-billing
| (view-financials). «Vendido frente a real»: también quien ve las bolsas, en horas
| (view-sold-vs-actual). Nunca se escribe en Holded.
|
| Una sola navegación (D-405, cambia D-393 y D-401): Resumen (/facturacion), Facturas, Por facturar,
| Vendido frente a real, Por revisar, Ventas y Ajustes. Las URL anteriores responden con un 301 a
| las nuevas con su query (MovedReportController), así siguen valiendo los favoritos, los enlaces
| de los correos y las descargas con ?formato=. Por facturar no exige el módulo (D-402): se ve con
| ClientPolicy::viewBilling, como antes.
*/

$exports = 'throttle:'.ReportsServiceProvider::EXPORT_LIMITER;

Route::get('facturacion/por-facturar', BillingReportController::class)
    ->middleware($exports)
    ->name('billing.unbilled');

// URL anteriores (D-401 y D-405): sin el módulo, como la página a la que llevan.
Route::get('facturacion/horas-para-facturar', MovedReportController::class)
    ->defaults('to', '/facturacion/por-facturar');
Route::get('informes/facturacion', MovedReportController::class)
    ->defaults('to', '/facturacion/por-facturar');
Route::get('informes/vendido-frente-a-real', MovedReportController::class)
    ->defaults('to', '/facturacion/vendido-frente-a-real');

Route::middleware('module:billing')->group(function () use ($exports) {
    // /facturacion: el Resumen. Hasta que llegue (I1), Ventas (view-billing) o, si solo ve el
    // vendido frente a real, ese (D-401 y D-405).
    Route::get('facturacion', BillingHomeController::class)->name('billing.index');

    Route::get('facturacion/ventas', InvoicingReportController::class)
        ->middleware(['can:view-billing', $exports])
        ->name('billing.sales');

    // URL anteriores (D-405): con el módulo, como las páginas a las que llevan.
    Route::get('facturacion/informe', MovedReportController::class)
        ->defaults('to', '/facturacion/ventas');
    Route::get('facturacion/contactos', MovedReportController::class)
        ->defaults('to', '/facturacion/por-revisar');

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

        // Por revisar (D-405): hoy, los contactos de Holded sin casar o por confirmar; con I5, también
        // las facturas sin proyecto.
        Route::get('facturacion/por-revisar', [HoldedContactController::class, 'index'])->name('billing.review');
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
