<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Holded\HoldedPdfStore;
use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\InvoiceLinkSuggester;
use App\Domain\Billing\InvoiceList;
use App\Domain\Billing\InvoicePresenter;
use App\Enums\BillingService;
use App\Enums\CollectionStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
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
 * Facturas leídas de Holded (Fase 12, F1; D-385): /facturacion/facturas (listado con vistas, barra
 * de importes, filtros, orden y totales, D-406 y D-407), su ficha (cabecera con el cobro, enlace con
 * proyecto o bolsa, línea de tiempo y anterior y siguiente del listado del que vienes, D-408) y su PDF
 * original. Solo lectura de Holded; quién: view-billing (en la ruta).
 */
class HoldedInvoiceController extends Controller
{
    /** Listado de ventas de Holded: el enlace exacto de cada documento no es público (D-408). */
    public const string HOLDED_SALES_URL = 'https://app.holded.com/sales/revenue';

    public function index(Request $request, InvoiceLinkSuggester $suggester): Response
    {
        /** @var array<string, mixed> $input */
        $input = $request->query();
        $list = InvoiceList::fromQuery($input);

        $page = $list->query()
            ->with(['client:id,name', 'links.project:id,code,name', 'links.hourBank:id,name'])
            ->paginate(InvoiceList::PER_PAGE, ['billing_documents.*'], 'pagina')
            ->withQueryString();

        /** @var list<BillingDocument> $items */
        $items = $page->items();
        // Las sugerencias de enlace son de las de Holded sin enlazar (D-388) ni marcadas «No necesita
        // proyecto» (D-431): las propias eligen su proyecto en el editor. Sus modelos, en una consulta.
        $unlinkedIds = array_values(array_map(fn (BillingDocument $invoice): int => $invoice->id, array_filter($items,
            fn (BillingDocument $invoice): bool => ! $invoice->isOwn() && $invoice->links->isEmpty() && $invoice->collection_status !== CollectionStatus::Cancelled && ! $invoice->noProjectNeeded())));
        $unlinked = $unlinkedIds === [] ? collect() : HoldedInvoice::query()->whereKey($unlinkedIds)->with('lines')->get()->keyBy('id');
        $suggester->prime(array_values($unlinked->all()));

        return Inertia::render('billing/invoices/index', [
            'invoices' => [
                // Sin enlazar: con su primera sugerencia, para aceptarla desde el listado (D-388).
                'data' => array_map(fn (BillingDocument $invoice): array => [
                    ...InvoicePresenter::summary($invoice),
                    'suggestion' => $unlinked->has($invoice->id) ? ($suggester->for($unlinked->get($invoice->id))[0] ?? null) : null,
                ], $items),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'total' => $page->total(),
                ],
                'links' => [
                    'prev' => $page->previousPageUrl(),
                    'next' => $page->nextPageUrl(),
                ],
            ],
            'filters' => $list->filters(),
            'list_query' => $list->toQuery(),
            'period' => $list->periodFor($list->view),
            'views' => $list->viewCounts(),
            'bar' => $list->collectionBar(),
            'totals' => $list->totals(),
            'clients' => Client::query()->whereIn('id', BillingDocument::withTests()->whereNotNull('client_id')->select('client_id'))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->values()->all(),
            'services' => array_map(fn (BillingService $service): string => $service->value, BillingService::cases()),
            'today' => $list->today->toDateString(),
        ]);
    }

    public function show(Request $request, HoldedInvoice $invoice, InvoiceLinkSuggester $suggester): Response
    {
        /** @var array<string, mixed> $input */
        $input = $request->query();
        $list = InvoiceList::fromQuery($input);
        $neighbours = $list->neighbours($invoice->id);
        $query = $list->toQuery();

        return Inertia::render('billing/invoices/show', [
            'invoice' => InvoicePresenter::detail($invoice),
            'suggestions' => $invoice->noProjectNeeded() || $invoice->links()->exists() ? [] : $suggester->for($invoice),
            'projects' => $this->linkableProjects($invoice),
            'holded' => HoldedConnection::summary(),
            'holded_url' => self::HOLDED_SALES_URL,
            // Duplicar como borrador propio (E1, D-428): con la emisión visible y un cliente casado.
            'can_duplicate' => $request->user()?->can('use-invoicing') === true && $invoice->client_id !== null,
            'today' => $list->today->toDateString(),
            // Del listado del que vienes (D-408): la miga «Facturas» con tus filtros y su página, y la
            // anterior y la siguiente con los mismos filtros.
            'list' => [
                'query' => $query,
                'back' => '/facturacion/facturas'.self::queryString([...$query, ...($neighbours['page'] > 1 ? ['pagina' => $neighbours['page']] : [])]),
                'previous' => $neighbours['previous'] === null ? null : BillingDocument::urlFor($neighbours['previous']).self::queryString($query),
                'next' => $neighbours['next'] === null ? null : BillingDocument::urlFor($neighbours['next']).self::queryString($query),
                'position' => $neighbours['position'],
                'total' => $neighbours['total'],
            ],
        ]);
    }

    /**
     * Proyectos con los que enlazarla a mano (D-408, amplía D-245): primero los del cliente de la
     * factura y después el resto de proyectos con cliente (antes solo los del cliente, FIC-3), sin
     * internos ni archivados, con sus bolsas (dos consultas).
     *
     * @return list<array<string, mixed>>
     */
    private function linkableProjects(HoldedInvoice $invoice): array
    {
        $projects = Project::query()
            ->whereNotNull('client_id')
            ->where('billing_type', '!=', 'internal')
            ->where(fn (Builder $q) => $q->where('status', '!=', ProjectStatus::Archived->value)
                ->when($invoice->client_id !== null, fn (Builder $own) => $own->orWhere('client_id', $invoice->client_id)))
            ->with(['client:id,name'])
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'client_id', 'billing_type', 'status']);

        $banks = HourBank::query()
            ->whereIn('project_id', $projects->filter(fn (Project $project): bool => $project->usesHourBanks())->pluck('id')->all())
            ->orderByDesc('start_date')->orderBy('id')
            ->get(['id', 'name', 'start_date', 'project_id'])
            ->groupBy('project_id');

        return array_values($projects
            ->sortBy(fn (Project $project): int => $project->client_id === $invoice->client_id ? 0 : 1)
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client?->name,
                'own_client' => $invoice->client_id !== null && $project->client_id === $invoice->client_id,
                'uses_banks' => $project->usesHourBanks(),
                'banks' => ($banks->get($project->id) ?? collect())
                    ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name, 'start_date' => $bank->start_date->toDateString()])->values()->all(),
            ])->all());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function queryString(array $query): string
    {
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $string === '' ? '' : '?'.$string;
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
