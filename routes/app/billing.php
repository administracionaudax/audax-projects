<?php

use App\Http\Controllers\Billing\BillingHomeController;
use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Billing\ClientBillingController;
use App\Http\Controllers\Billing\HoldedContactClientController;
use App\Http\Controllers\Billing\HoldedContactController;
use App\Http\Controllers\Billing\HoldedInvoiceController;
use App\Http\Controllers\Billing\HoldedInvoiceLinkController;
use App\Http\Controllers\Billing\InvoicingEmailSettingsController;
use App\Http\Controllers\Billing\InvoicingReportController;
use App\Http\Controllers\Billing\InvoicingSettingsController;
use App\Http\Controllers\Billing\MovedReportController;
use App\Http\Controllers\Billing\NoProjectNeededController;
use App\Http\Controllers\Billing\ProjectBillingController;
use App\Http\Controllers\Billing\RecurringInvoiceController;
use App\Http\Controllers\Billing\ReviewController;
use App\Http\Controllers\Billing\SalesDocumentAttachmentController;
use App\Http\Controllers\Billing\SalesDocumentController;
use App\Http\Controllers\Billing\SalesDocumentEmailController;
use App\Http\Controllers\Billing\SalesDocumentPaymentController;
use App\Http\Controllers\Billing\SalesDocumentScheduleController;
use App\Http\Controllers\Billing\SoldVsActualController;
use App\Http\Controllers\Billing\WorkDraftController;
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
    // Con I5 (D-413): las vistas de trabajo, a la pestaña de contactos; Todos y Descartados, a Ajustes.
    Route::get('facturacion/contactos', [HoldedContactController::class, 'moved']);

    Route::get('facturacion/vendido-frente-a-real', SoldVsActualController::class)
        ->middleware(['can:view-sold-vs-actual', $exports])
        ->name('billing.sold-vs-actual');

    Route::get('proyectos/{project}/facturacion', ProjectBillingController::class)
        ->whereNumber('project')
        ->middleware('can:view-sold-vs-actual')
        ->name('projects.billing');

    Route::middleware('can:view-billing')->group(function () {
        Route::get('facturacion/facturas', [HoldedInvoiceController::class, 'index'])->name('billing.invoices.index');
        // «Por cobrar» (E2, D-437): una vista de Facturas, con la antigüedad por cliente.
        Route::get('facturacion/por-cobrar', MovedReportController::class)
            ->defaults('to', '/facturacion/facturas?vista=por-cobrar')
            ->name('billing.receivables');
        Route::get('facturacion/facturas/{invoice}', [HoldedInvoiceController::class, 'show'])->whereNumber('invoice')->name('billing.invoices.show');
        Route::get('facturacion/facturas/{invoice}/pdf', [HoldedInvoiceController::class, 'pdf'])
            ->whereNumber('invoice')
            ->middleware('throttle:60,1,billing.invoices.pdf')
            ->name('billing.invoices.pdf');
        Route::post('facturacion/facturas/{invoice}/enlaces', [HoldedInvoiceLinkController::class, 'store'])->whereNumber('invoice')->name('billing.invoices.links.store');
        Route::delete('facturacion/facturas/{invoice}/enlaces/{link}', [HoldedInvoiceLinkController::class, 'destroy'])
            ->whereNumber(['invoice', 'link'])
            ->name('billing.invoices.links.destroy');

        // Por revisar (I5, D-413 y D-414): contactos sin casar o por confirmar y facturas sin proyecto,
        // con «Aceptar las de confianza alta» y «Deshacer».
        Route::get('facturacion/por-revisar', [ReviewController::class, 'index'])->name('billing.review');
        Route::post('facturacion/por-revisar/aceptar', [ReviewController::class, 'accept'])
            ->middleware('throttle:30,1,billing.review.accept')
            ->name('billing.review.accept');
        Route::post('facturacion/por-revisar/deshacer', [ReviewController::class, 'undo'])->name('billing.review.undo');
        Route::put('facturacion/contactos/{contact}', [HoldedContactController::class, 'update'])->whereNumber('contact')->name('billing.contacts.update');

        Route::get('facturacion/ajustes', [BillingSettingsController::class, 'edit'])->name('billing.settings');
        Route::put('facturacion/ajustes', [BillingSettingsController::class, 'update'])->name('billing.settings.update');
        Route::put('facturacion/ajustes/acceso', [BillingSettingsController::class, 'updateAccess'])->name('billing.settings.access');
        Route::post('facturacion/sincronizar', [BillingSettingsController::class, 'sync'])
            ->middleware(['can:sync-holded', 'throttle:6,1,billing.sync'])
            ->name('billing.sync');

        Route::get('clientes/{client}/facturacion', [ClientBillingController::class, 'show'])->whereNumber('client')->name('clients.billing');
        Route::put('clientes/{client}/datos-fiscales', [ClientBillingController::class, 'update'])->whereNumber('client')->name('clients.billing.update');

        // Respuestas del propietario del 09/10 (D-430 y D-431): crear el cliente desde un contacto de
        // Holded (además, ClientPolicy::create) y «No necesita proyecto» de una factura.
        Route::get('facturacion/contactos/{contact}/cliente', [HoldedContactClientController::class, 'show'])->whereNumber('contact')->name('billing.contacts.client.draft');
        Route::post('facturacion/contactos/{contact}/cliente', [HoldedContactClientController::class, 'store'])
            ->whereNumber('contact')
            ->middleware('throttle:30,1,billing.contacts.client')
            ->name('billing.contacts.client.store');
        Route::post('facturacion/facturas/{invoice}/sin-proyecto', [NoProjectNeededController::class, 'store'])->whereNumber('invoice')->name('billing.invoices.no-project.store');
        Route::delete('facturacion/facturas/{invoice}/sin-proyecto', [NoProjectNeededController::class, 'destroy'])->whereNumber('invoice')->name('billing.invoices.no-project.destroy');
    });

    /*
    | Emisión propia (PLAN-EMISION E1; D-417 a D-429), detrás también del módulo `invoicing`
    | (apagado: 404; en modo de prueba, solo admins). Preparar borradores: use-invoicing; emitir,
    | anular y rectificar: manage-billing; anular el registro: void-invoices (comprobado en el
    | controlador con la matriz DocumentActions). Nunca se llama a la AEAT ni se escribe en Holded.
    */
    Route::middleware(['module:invoicing', 'can:use-invoicing'])->group(function () {
        Route::get('facturacion/facturas/nueva', [SalesDocumentController::class, 'create'])->name('billing.documents.create');
        Route::post('facturacion/facturas/{invoice}/duplicar', [SalesDocumentController::class, 'duplicateHolded'])->whereNumber('invoice')->name('billing.invoices.duplicate');
        Route::post('facturacion/documentos', [SalesDocumentController::class, 'store'])->name('billing.documents.store');
        Route::post('facturacion/documentos/vista-previa', [SalesDocumentController::class, 'preview'])
            ->middleware('throttle:30,1,billing.documents.preview')
            ->name('billing.documents.preview');

        Route::prefix('facturacion/documentos/{document}')->whereNumber('document')->group(function () {
            Route::get('/', [SalesDocumentController::class, 'show'])->name('billing.documents.show');
            Route::get('editar', [SalesDocumentController::class, 'edit'])->name('billing.documents.edit');
            Route::put('/', [SalesDocumentController::class, 'update'])->name('billing.documents.update');
            Route::delete('/', [SalesDocumentController::class, 'destroy'])->name('billing.documents.destroy');
            Route::get('pdf', [SalesDocumentController::class, 'pdf'])
                ->middleware('throttle:60,1,billing.documents.pdf')
                ->name('billing.documents.pdf');
            Route::put('no-fiscal', [SalesDocumentController::class, 'updateNonFiscal'])->name('billing.documents.non-fiscal');
            // «No necesita proyecto» (D-431), como en las de Holded.
            Route::post('sin-proyecto', [SalesDocumentController::class, 'markNoProject'])->name('billing.documents.no-project.store');
            Route::delete('sin-proyecto', [SalesDocumentController::class, 'clearNoProject'])->name('billing.documents.no-project.destroy');
            Route::post('duplicar', [SalesDocumentController::class, 'duplicate'])->name('billing.documents.duplicate');

            // E2 (D-436 a D-444): cobros, envío por email y recordatorio a mano, y adjuntos. Cada
            // acción la comprueba la matriz DocumentActions (cobrar y enviar, manage-billing).
            Route::post('cobros', [SalesDocumentPaymentController::class, 'store'])->middleware('throttle:30,1,billing.documents.payments')->name('billing.documents.payments.store');
            Route::post('cobros/{payment}/anular', [SalesDocumentPaymentController::class, 'void'])->whereNumber('payment')->name('billing.documents.payments.void');
            Route::get('envio', [SalesDocumentEmailController::class, 'compose'])->name('billing.documents.emails.compose');
            Route::post('envios', [SalesDocumentEmailController::class, 'store'])->middleware('throttle:20,1,billing.documents.emails')->name('billing.documents.emails.store');
            Route::post('adjuntos', [SalesDocumentAttachmentController::class, 'store'])->middleware('throttle:30,1,billing.documents.attachments')->name('billing.documents.attachments.store');
            Route::get('adjuntos/{attachment}', [SalesDocumentAttachmentController::class, 'show'])->whereNumber('attachment')->name('billing.documents.attachments.show');
            Route::put('adjuntos/{attachment}', [SalesDocumentAttachmentController::class, 'update'])->whereNumber('attachment')->name('billing.documents.attachments.update');
            Route::delete('adjuntos/{attachment}', [SalesDocumentAttachmentController::class, 'destroy'])->whereNumber('attachment')->name('billing.documents.attachments.destroy');

            Route::middleware('can:manage-billing')->group(function () {
                Route::post('emitir', [SalesDocumentController::class, 'issue'])->middleware('throttle:30,1,billing.documents.issue')->name('billing.documents.issue');
                Route::post('anular', [SalesDocumentController::class, 'cancel'])->name('billing.documents.cancel');
                Route::post('rectificar', [SalesDocumentController::class, 'rectify'])->name('billing.documents.rectify');
                Route::post('anular-registro', [SalesDocumentController::class, 'void'])->middleware('can:void-invoices')->name('billing.documents.void');
            });
        });

        /*
        | E3 (D-450 en adelante): recurrentes (verlas, use-invoicing; el resto, manage-billing, porque
        | pueden emitir solas), programar la emisión de un borrador (manage-billing, comprobado con la
        | matriz) y «Crear borrador» desde el trabajo (quien prepara, use-invoicing).
        */
        Route::get('facturacion/recurrentes', [RecurringInvoiceController::class, 'index'])->name('billing.recurring.index');
        Route::get('facturacion/recurrentes/{recurring}', [RecurringInvoiceController::class, 'show'])->whereNumber('recurring')->name('billing.recurring.show');
        Route::middleware('can:manage-billing')->group(function () {
            Route::get('facturacion/recurrentes/nueva', [RecurringInvoiceController::class, 'create'])->name('billing.recurring.create');
            Route::post('facturacion/recurrentes', [RecurringInvoiceController::class, 'store'])->name('billing.recurring.store');
            Route::prefix('facturacion/recurrentes/{recurring}')->whereNumber('recurring')->group(function () {
                Route::get('editar', [RecurringInvoiceController::class, 'edit'])->name('billing.recurring.edit');
                Route::put('/', [RecurringInvoiceController::class, 'update'])->name('billing.recurring.update');
                Route::delete('/', [RecurringInvoiceController::class, 'destroy'])->name('billing.recurring.destroy');
                Route::post('pausar', [RecurringInvoiceController::class, 'pause'])->name('billing.recurring.pause');
                Route::post('reanudar', [RecurringInvoiceController::class, 'resume'])->name('billing.recurring.resume');
                Route::post('omitir', [RecurringInvoiceController::class, 'skip'])->name('billing.recurring.skip');
                Route::post('generar', [RecurringInvoiceController::class, 'generate'])->middleware('throttle:30,1,billing.recurring.generate')->name('billing.recurring.generate');
                Route::post('revisada', [RecurringInvoiceController::class, 'review'])->name('billing.recurring.review');
            });
            Route::post('facturacion/documentos/{document}/programar', [SalesDocumentScheduleController::class, 'store'])->whereNumber('document')->name('billing.documents.schedule');
            Route::delete('facturacion/documentos/{document}/programar', [SalesDocumentScheduleController::class, 'destroy'])->whereNumber('document')->name('billing.documents.unschedule');
        });
        Route::get('facturacion/por-facturar/clientes/{client}/borrador', [WorkDraftController::class, 'show'])->whereNumber('client')->name('billing.unbilled.draft');
        Route::post('facturacion/por-facturar/clientes/{client}/borrador', [WorkDraftController::class, 'store'])
            ->whereNumber('client')
            ->middleware('throttle:30,1,billing.unbilled.draft')
            ->name('billing.unbilled.draft.store');

        // Opciones de facturación del cliente (E2, D-442 y D-443): facturas en el portal y recordatorios.
        Route::put('clientes/{client}/facturacion/opciones', [ClientBillingController::class, 'updateOptions'])->whereNumber('client')->name('clients.billing.options');

        // Ajustes de la emisión (D-419, D-423, D-425 y D-426): verlos con use-invoicing; cambiarlos, manage-billing.
        Route::middleware('can:manage-billing')->prefix('facturacion/ajustes/emision')->group(function () {
            Route::put('series/{series}', [InvoicingSettingsController::class, 'updateSeries'])->whereNumber('series')->name('billing.settings.series.update');
            Route::put('series/{series}/contador', [InvoicingSettingsController::class, 'updateCounter'])->whereNumber('series')->name('billing.settings.series.counter');
            Route::post('impuestos', [InvoicingSettingsController::class, 'storeTax'])->name('billing.settings.taxes.store');
            Route::put('impuestos/{tax}', [InvoicingSettingsController::class, 'updateTax'])->whereNumber('tax')->name('billing.settings.taxes.update');
            Route::post('servicios', [InvoicingSettingsController::class, 'storeService'])->name('billing.settings.services.store');
            Route::put('servicios/{service}', [InvoicingSettingsController::class, 'updateService'])->whereNumber('service')->name('billing.settings.services.update');
            Route::post('servicios/importar', [InvoicingSettingsController::class, 'importServices'])->name('billing.settings.services.import');
            Route::post('formas-de-pago', [InvoicingSettingsController::class, 'storePaymentMethod'])->name('billing.settings.payment-methods.store');
            Route::put('formas-de-pago/{method}', [InvoicingSettingsController::class, 'updatePaymentMethod'])->whereNumber('method')->name('billing.settings.payment-methods.update');
            Route::put('documento', [InvoicingSettingsController::class, 'updateDocument'])->name('billing.settings.document.update');
            Route::post('documento/logo', [InvoicingSettingsController::class, 'uploadLogo'])->name('billing.settings.document.logo');
            Route::delete('documento/logo', [InvoicingSettingsController::class, 'deleteLogo'])->name('billing.settings.document.logo.destroy');

            // E2 (D-438, D-439 y D-442): remitente, plantillas de email y escalones de los recordatorios.
            Route::put('correo', [InvoicingEmailSettingsController::class, 'updateSender'])->name('billing.settings.email.update');
            Route::put('plantillas/{kind}/{language}', [InvoicingEmailSettingsController::class, 'updateTemplate'])->name('billing.settings.templates.update');
            Route::delete('plantillas/{kind}/{language}', [InvoicingEmailSettingsController::class, 'resetTemplate'])->name('billing.settings.templates.reset');
            Route::post('recordatorios', [InvoicingEmailSettingsController::class, 'storeRule'])->name('billing.settings.reminders.store');
            Route::put('recordatorios/{rule}', [InvoicingEmailSettingsController::class, 'updateRule'])->whereNumber('rule')->name('billing.settings.reminders.update');
            Route::delete('recordatorios/{rule}', [InvoicingEmailSettingsController::class, 'destroyRule'])->whereNumber('rule')->name('billing.settings.reminders.destroy');
        });
    });
});
