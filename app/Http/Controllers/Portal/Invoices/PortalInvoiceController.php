<?php

namespace App\Http\Controllers\Portal\Invoices;

use App\Domain\Billing\Issuing\InvoiceLinks;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Domain\Billing\Issuing\PortalInvoices;
use App\Domain\Portal\PortalScope;
use App\Http\Controllers\Billing\SalesDocumentAttachmentController;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal · Facturas (PLAN-EMISION §3.5; E2, D-443): las facturas propias emitidas del cliente con su
 * estado de cobro, lo pendiente, la forma de pago, el PDF y los adjuntos visibles. Todo sale de
 * PortalScope y PortalInvoices: una factura de otro cliente, un borrador, una anulada por error o de
 * la serie de pruebas da 404 (como una que no existe), y con «Facturas en el portal» apagado o la
 * emisión sin encender, 404 también.
 *
 * Consultas fijas: el cliente, si tiene las facturas encendidas, las facturas y sus adjuntos visibles.
 */
class PortalInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $scope = $this->scope($request);

        $documents = PortalInvoices::query($scope)
            ->with(['attachments' => fn ($q) => $q->where('client_visible', true)->select(['id', 'attachable_type', 'attachable_id', 'original_name', 'size'])])
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(500)
            ->get();

        $pending = '0.00';
        $overdue = '0.00';
        $today = LocalTime::todayString();
        foreach ($documents as $document) {
            $amount = $document->pendingAmount();
            $pending = bcadd($pending, $amount, 2);
            if ($document->due_date !== null && $document->due_date->toDateString() < $today) {
                $overdue = bcadd($overdue, $amount, 2);
            }
        }

        return Inertia::render('portal/invoices/index', [
            'client' => ['name' => $scope->client->name],
            'summary' => ['pending' => $pending, 'overdue' => $overdue, 'count' => $documents->count()],
            'invoices' => $documents->map(fn (SalesDocument $document): array => [
                ...PortalInvoices::summary($document),
                'attachments' => $document->attachments->map(fn (Attachment $one): array => ['id' => $one->id, 'name' => $one->original_name, 'size' => $one->size])->values()->all(),
            ])->values()->all(),
            'today' => $today,
        ]);
    }

    public function pdf(Request $request, int $document, InvoicePdf $pdf): SymfonyResponse
    {
        $scope = $this->scope($request);
        $model = PortalInvoices::query($scope)->whereKey($document)->firstOrFail();

        /** @var User $user */
        $user = $request->user();
        InvoiceLinks::recordView($model, null, $user);

        return PortalInvoices::pdfResponse($model, $pdf, $request->boolean('descargar'));
    }

    public function attachment(Request $request, int $document, int $attachment): StreamedResponse
    {
        $scope = $this->scope($request);
        $model = PortalInvoices::query($scope)->whereKey($document)->firstOrFail();
        $file = Attachment::query()->whereKey($attachment)
            ->where('attachable_type', $model->getMorphClass())->where('attachable_id', $model->id)
            ->where('client_visible', true)->firstOrFail();

        return SalesDocumentAttachmentController::serve($file);
    }

    private function scope(Request $request): PortalScope
    {
        /** @var User $user */
        $user = $request->user();
        $scope = PortalScope::for($user);
        abort_unless(PortalInvoices::enabledFor($scope), 404);

        return $scope;
    }
}
