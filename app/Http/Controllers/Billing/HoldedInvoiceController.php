<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Holded\HoldedPdfStore;
use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\InvoiceLinkSuggester;
use App\Domain\Billing\InvoicePresenter;
use App\Domain\Reports\Money;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Facturas leídas de Holded (Fase 12, F1; D-385): /facturacion/facturas (listado con filtros y
 * sumatorio), su ficha (líneas, cobros, rectificativas y enlaces con proyectos y bolsas) y su PDF
 * original. Solo lectura de Holded; quién: view-billing (en la ruta).
 */
class HoldedInvoiceController extends Controller
{
    public const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', CollectionStatus::values())],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', HoldedDocumentKind::values())],
            'cliente' => ['nullable', 'integer'],
            'enlace' => ['nullable', 'string', 'in:con,sin'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = HoldedInvoice::query()
            ->when($filters['buscar'] ?? null, function (Builder $q, string $search): void {
                $like = '%'.mb_strtolower(trim($search)).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$like])
                    ->orWhereHas('client', fn (Builder $c) => $c->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['estado'] ?? null, fn (Builder $q, string $status) => $q->where('collection_status', $status))
            ->when($filters['tipo'] ?? null, fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->when($filters['cliente'] ?? null, fn (Builder $q, int $client) => $q->where('client_id', $client))
            ->when(($filters['enlace'] ?? null) === 'con', fn (Builder $q) => $q->whereHas('links'))
            ->when(($filters['enlace'] ?? null) === 'sin', fn (Builder $q) => $q->whereDoesntHave('links'))
            ->when($filters['desde'] ?? null, fn (Builder $q, string $from) => $q->where('issued_on', '>=', $from))
            ->when($filters['hasta'] ?? null, fn (Builder $q, string $to) => $q->where('issued_on', '<=', $to));

        $totals = HoldedInvoice::countingIn(clone $query)->toBase()->selectRaw('COUNT(*) as count, COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(total), 0) as total, COALESCE(SUM(paid_total), 0) as paid, COALESCE(SUM(pending_total), 0) as pending')->first();

        $page = $query->with(['client:id,name', 'links.project:id,code,name', 'links.hourBank:id,name', 'lines'])
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: 'pagina')->withQueryString();
        $suggester = app(InvoiceLinkSuggester::class);

        return Inertia::render('billing/invoices/index', [
            'invoices' => [
                // Sin enlazar: con su primera sugerencia, para aceptarla desde el listado (D-388).
                'data' => array_map(fn (HoldedInvoice $invoice): array => [
                    ...InvoicePresenter::summary($invoice),
                    'suggestion' => $invoice->links->isEmpty() && $invoice->collection_status !== CollectionStatus::Cancelled ? ($suggester->for($invoice)[0] ?? null) : null,
                ], $page->items()),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'totals' => [
                'count' => (int) ($totals->count ?? 0),
                'subtotal' => Money::round(Money::of((string) ($totals->subtotal ?? '0'))),
                'total' => Money::round(Money::of((string) ($totals->total ?? '0'))),
                'paid' => Money::round(Money::of((string) ($totals->paid ?? '0'))),
                'pending' => Money::round(Money::of((string) ($totals->pending ?? '0'))),
            ],
            'filters' => [
                'buscar' => $filters['buscar'] ?? '',
                'estado' => $filters['estado'] ?? null,
                'tipo' => $filters['tipo'] ?? null,
                'cliente' => isset($filters['cliente']) ? (int) $filters['cliente'] : null,
                'enlace' => $filters['enlace'] ?? null,
                'desde' => $filters['desde'] ?? null,
                'hasta' => $filters['hasta'] ?? null,
            ],
            'clients' => Client::query()->whereIn('id', HoldedInvoice::query()->whereNotNull('client_id')->select('client_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->values()->all(),
            'unlinked' => HoldedInvoice::query()->whereDoesntHave('links')->where('collection_status', '!=', CollectionStatus::Cancelled->value)->count(),
            'last_sync' => BillingSettingsController::lastSync(),
        ]);
    }

    public function show(HoldedInvoice $invoice): Response
    {
        return Inertia::render('billing/invoices/show', [
            'invoice' => InvoicePresenter::detail($invoice),
            'suggestions' => $invoice->links()->exists() ? [] : app(InvoiceLinkSuggester::class)->for($invoice),
            // Proyectos del cliente (y sus bolsas) para enlazarla a mano; sin cliente, todos los que tienen cliente.
            'projects' => Project::query()
                ->whereNotNull('client_id')
                ->when($invoice->client_id !== null, fn (Builder $q) => $q->where('client_id', $invoice->client_id))
                ->where('billing_type', '!=', 'internal')
                ->with(['client:id,name'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'client_id', 'billing_type'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'client' => $project->client?->name,
                    'uses_banks' => $project->usesHourBanks(),
                    'banks' => $project->usesHourBanks()
                        ? HourBank::query()->where('project_id', $project->id)->orderByDesc('start_date')->orderBy('id')->get(['id', 'name', 'start_date'])
                            ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name, 'start_date' => $bank->start_date->toDateString()])->values()->all()
                        : [],
                ])->values()->all(),
            'holded' => HoldedConnection::summary(),
        ]);
    }

    /** El PDF original (el guardado o, si aún no está, el de Holded, que se guarda). */
    public function pdf(Request $request, HoldedInvoice $invoice): SymfonyResponse
    {
        try {
            $content = HoldedPdfStore::contents(fn (): HoldedApi => app(HoldedApi::class), $invoice);
        } catch (HoldedRequestFailed $e) {
            report($e);
            abort(503, $e->getMessage());
        }

        $disposition = $request->boolean('descargar') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $invoice->pdfFilename()),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
