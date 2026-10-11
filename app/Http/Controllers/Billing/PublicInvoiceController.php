<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\InvoiceLinks;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Domain\Billing\Issuing\PortalInvoices;
use App\Domain\Identity\CompanyIdentity;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\SalesDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\SalesDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * La factura para el cliente desde el enlace firmado del email (PLAN-EMISION §3.4, H-098; E2,
 * D-440), sin usuario: el número, las fechas, el total, lo pendiente y su estado, el PDF (ver y
 * descargar) y los adjuntos visibles. Nunca la nota interna, los proyectos ni nada de la plantilla.
 *
 * - Enlace manipulado: 403 con una página que lo explica; caducado (60 días): 410, con a quién
 *   pedir uno nuevo. Las respuestas no se guardan en ninguna caché.
 * - Cada visita del cliente (no de la plantilla con sesión) se anota: «Vista por el cliente».
 * - Con la emisión apagada (y fuera del modo de prueba), 404, como el resto del módulo.
 */
class PublicInvoiceController extends Controller
{
    public function show(Request $request, string $document, CompanyIdentity $identity): SymfonyResponse
    {
        $model = $this->resolve($document);
        $language = $model->language === 'en' ? 'en' : 'es';

        if (($problem = $this->signatureProblem($request)) !== null) {
            return Inertia::render('public/invoice', [
                'problem' => $problem,
                'company' => $identity->forPortal(),
                'texts' => (array) __("invoicing.public.{$language}"),
                'invoice' => null,
            ])->toResponse($request)->setStatusCode($problem === 'expired' ? 410 : 403)->setPrivate()->setMaxAge(0);
        }

        $emailId = $request->integer('envio') ?: null;
        /** @var User|null $viewer */
        $viewer = $request->user();
        InvoiceLinks::recordView($model, $emailId, $viewer);
        $expires = CarbonImmutable::createFromTimestamp((int) $request->query('expires'));
        $model->load(['attachments' => fn ($q) => $q->where('client_visible', true)]);

        return Inertia::render('public/invoice', [
            'problem' => null,
            'company' => $identity->forPortal(),
            'texts' => (array) __("invoicing.public.{$language}"),
            'invoice' => [
                ...PortalInvoices::summary($model, $language),
                'pdf_url' => InvoiceLinks::pdf($model, $emailId, $expires),
                'download_url' => InvoiceLinks::pdf($model, $emailId, $expires, true),
                'attachments' => $model->attachments->map(fn (Attachment $one): array => [
                    'id' => $one->id,
                    'name' => $one->original_name,
                    'size' => $one->size,
                    'url' => InvoiceLinks::attachment($model, $one->id, $emailId, $expires),
                ])->values()->all(),
                'expires_at' => $expires->utc()->toIso8601ZuluString(),
            ],
        ])->toResponse($request)->setPrivate()->setMaxAge(0);
    }

    public function pdf(Request $request, string $document, InvoicePdf $pdf): SymfonyResponse
    {
        $model = $this->resolve($document);
        abort_if($this->signatureProblem($request) !== null, 403);

        /** @var User|null $viewer */
        $viewer = $request->user();
        InvoiceLinks::recordView($model, $request->integer('envio') ?: null, $viewer);

        return PortalInvoices::pdfResponse($model, $pdf, $request->boolean('descargar'));
    }

    public function attachment(Request $request, string $document, Attachment $attachment): SymfonyResponse
    {
        $model = $this->resolve($document);
        abort_if($this->signatureProblem($request) !== null, 403);
        abort_unless($attachment->client_visible && $attachment->attachable_type === $model->getMorphClass() && $attachment->attachable_id === $model->id, 404);

        return SalesDocumentAttachmentController::serve($attachment);
    }

    /** La factura del enlace: emitida (o anulada), con la emisión visible para alguien. */
    private function resolve(string $uuid): SalesDocument
    {
        abort_unless(AppModules::enabled(AppModule::Billing) && AppModules::enabled(AppModule::Invoicing) || AppModules::previewMode(), 404);

        $document = SalesDocument::query()->where('uuid', $uuid)->first();
        abort_if($document === null || $document->status === SalesDocumentStatus::Draft, 404);

        return $document;
    }

    /** null si la firma vale; `invalid` si está manipulada; `expired` si ha caducado. */
    private function signatureProblem(Request $request): ?string
    {
        if (! URL::hasCorrectSignature($request)) {
            return 'invalid';
        }

        return URL::signatureHasNotExpired($request) ? null : 'expired';
    }
}
