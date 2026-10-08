<?php

use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Billing\ClientBillingController;
use App\Http\Controllers\Billing\HoldedContactController;
use App\Http\Controllers\Billing\HoldedInvoiceController;
use App\Http\Controllers\Billing\HoldedInvoiceLinkController;
use App\Http\Controllers\Billing\ProjectBillingController;
use App\Http\Controllers\Billing\SoldVsActualController;
use App\Providers\ReportsServiceProvider;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facturación (Fase 12, F1; D-380 a D-399): lectura de Holded y «Vendido frente a real». Nombres
| billing.* (y reports.sold-vs-actual, projects.billing y clients.billing en sus áreas).
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', 'collaborator', '2fa'].
| Todo detrás del módulo `billing` (apagado, 404; en modo de prueba, solo admins, D-239). Importes,
| facturas, cobros, contactos y datos fiscales: view-billing (view-financials). El informe: también
| quien ve las bolsas, en horas (view-sold-vs-actual). Nunca se escribe en Holded.
*/

Route::middleware('module:billing')->group(function () {
    Route::get('informes/vendido-frente-a-real', SoldVsActualController::class)
        ->middleware(['can:view-sold-vs-actual', 'throttle:'.ReportsServiceProvider::EXPORT_LIMITER])
        ->name('reports.sold-vs-actual');

    Route::get('proyectos/{project}/facturacion', ProjectBillingController::class)
        ->whereNumber('project')
        ->middleware('can:view-sold-vs-actual')
        ->name('projects.billing');

    Route::middleware('can:view-billing')->group(function () {
        Route::redirect('facturacion', '/facturacion/facturas')->name('billing.index');

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
        Route::post('facturacion/sincronizar', [BillingSettingsController::class, 'sync'])
            ->middleware(['can:sync-holded', 'throttle:6,1,billing.sync'])
            ->name('billing.sync');

        Route::get('clientes/{client}/facturacion', [ClientBillingController::class, 'show'])->whereNumber('client')->name('clients.billing');
        Route::put('clientes/{client}/datos-fiscales', [ClientBillingController::class, 'update'])->whereNumber('client')->name('clients.billing.update');
    });
});
