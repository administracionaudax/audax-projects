<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\InvoiceList;
use App\Domain\Billing\Issuing\EditorOptions;
use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Domain\Billing\Recurring\RecurringInvoiceGenerator;
use App\Domain\Billing\Recurring\RecurringInvoices;
use App\Domain\Billing\Recurring\RecurringPresenter;
use App\Enums\SalesDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RecurringInvoiceRequest;
use App\Models\HoldedInvoice;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facturas recurrentes (PLAN-EMISION §3.1 y §6.2; E3): la vista «Recurrentes» de Facturas, la ficha
 * (calendario de las próximas 12 e historial), el editor y sus acciones (pausar, reanudar, omitir un
 * periodo, «Generar ahora», marcar revisada una importada de Holded y borrar). Ver: use-invoicing
 * (en la ruta); crear, cambiar y el resto: manage-billing (una recurrente puede emitir sola).
 */
class RecurringInvoiceController extends Controller
{
    public const array STATES = ['activas', 'pausadas', 'acabadas', 'todas'];

    public function index(Request $request): Response
    {
        $state = in_array($request->query('estado'), self::STATES, true) ? (string) $request->query('estado') : 'todas';
        $search = trim((string) $request->query('buscar', ''));
        $all = RecurringInvoice::query()->with(RecurringPresenter::LIST_RELATIONS)
            ->orderByRaw('CASE WHEN finished_at IS NULL AND next_run_on IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('next_run_on')->orderBy('name')->orderBy('id')
            ->limit(500)->get();

        $counts = [
            'activas' => $all->filter(fn (RecurringInvoice $one): bool => $one->state() === 'active')->count(),
            'pausadas' => $all->filter(fn (RecurringInvoice $one): bool => $one->state() === 'paused')->count(),
            'acabadas' => $all->filter(fn (RecurringInvoice $one): bool => $one->state() === 'finished')->count(),
            'todas' => $all->count(),
        ];
        $wanted = ['activas' => 'active', 'pausadas' => 'paused', 'acabadas' => 'finished'][$state] ?? null;
        $needle = mb_strtolower($search);
        $shown = $all->filter(fn (RecurringInvoice $one): bool => ($wanted === null || $one->state() === $wanted)
            && ($needle === '' || str_contains(mb_strtolower($one->name.' '.($one->client->name ?? '')), $needle)))->values();

        return Inertia::render('billing/recurring/index', [
            'recurrences' => RecurringPresenter::rows($shown),
            'counts' => $counts,
            'filters' => ['estado' => $state, 'buscar' => $search],
            // Las pestañas de Facturas (la vista «Recurrentes» es una más, D-406).
            'views' => InvoiceList::fromQuery([])->viewCounts(),
            'pending_review' => $all->filter(fn (RecurringInvoice $one): bool => $one->holded_recurring_id !== null && $one->reviewed_at === null)->count(),
            'can_manage' => InvoicingAccess::manages($request->user()),
            'auto_active' => RecurringInvoiceGenerator::active(),
        ]);
    }

    public function show(Request $request, RecurringInvoice $recurring): Response
    {
        return Inertia::render('billing/recurring/show', [
            'recurring' => RecurringPresenter::detail($recurring),
            'can_manage' => InvoicingAccess::manages($request->user()),
            'auto_active' => RecurringInvoiceGenerator::active(),
            'today' => LocalTime::todayString(),
        ]);
    }

    /** Nueva, en blanco o «Hacer recurrente» desde una factura propia (?desde=) o de Holded (?desde_holded=). */
    public function create(Request $request): Response
    {
        $defaults = null;
        if (($id = $request->integer('desde')) > 0) {
            $source = SalesDocument::query()->findOrFail($id);
            abort_if($source->isCreditNote() || in_array($source->status, [SalesDocumentStatus::Cancelled, SalesDocumentStatus::Voided], true), 404);
            $defaults = RecurringInvoices::fromDocument($source);
        } elseif (($id = $request->integer('desde_holded')) > 0) {
            $defaults = RecurringInvoices::fromDocument(HoldedInvoice::query()->findOrFail($id));
        } elseif (($client = $request->integer('cliente')) > 0) {
            $defaults = ['client_id' => $client, 'start_date' => LocalTime::today()->addMonthNoOverflow()->startOfMonth()->toDateString(), 'day_of_month' => 1];
        }
        $defaults ??= ['start_date' => LocalTime::today()->addMonthNoOverflow()->startOfMonth()->toDateString(), 'day_of_month' => 1];

        return Inertia::render('billing/recurring/edit', [
            'recurring' => RecurringPresenter::form(null, $defaults),
            'options' => EditorOptions::all(),
        ]);
    }

    public function store(RecurringInvoiceRequest $request, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recurring = $service->save(null, $request->recurring(), $user); // @phpstan-ignore argument.type
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.saved')]);

        return redirect($recurring->url());
    }

    public function edit(RecurringInvoice $recurring): Response
    {
        return Inertia::render('billing/recurring/edit', [
            'recurring' => RecurringPresenter::form($recurring),
            'options' => EditorOptions::all(),
        ]);
    }

    public function update(RecurringInvoiceRequest $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->save($recurring, $request->recurring(), $user); // @phpstan-ignore argument.type
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.saved')]);

        return redirect($recurring->url());
    }

    public function destroy(Request $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->delete($recurring, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.deleted')]);

        return redirect('/facturacion/recurrentes');
    }

    public function pause(Request $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->pause($recurring, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.paused')]);

        return back();
    }

    public function resume(Request $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->resume($recurring, $user);
        $recurring->refresh();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.resumed', ['date' => $recurring->next_run_on?->format('d/m/Y') ?? '—'])]);

        return back();
    }

    public function skip(Request $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['period' => ['required', 'date_format:Y-m-d'], 'skip' => ['required', 'boolean']]);
        $period = CarbonImmutable::parse((string) $data['period']);
        $service->skip($recurring, $period, (bool) $data['skip'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($data['skip'] ? 'invoicing_work.recurring.flash.skipped' : 'invoicing_work.recurring.flash.unskipped', ['date' => $period->format('d/m/Y')])]);

        return back();
    }

    /** «Generar ahora»: la del próximo periodo, ya (también en modo de prueba). */
    public function generate(Request $request, RecurringInvoice $recurring, RecurringInvoiceGenerator $generator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($recurring->isFinished()) {
            throw ValidationException::withMessages(['recurring' => __('invoicing_work.recurring.errors.finished')]);
        }

        $run = $generator->generateNow($recurring, $user);
        /** @var SalesDocument $document */
        $document = SalesDocument::query()->findOrFail($run->sales_document_id);
        $message = match (true) {
            $run->error !== null => __('invoicing_work.recurring.flash.generated_problem', ['reason' => $run->error]),
            ! $document->isDraft() => __('invoicing_work.recurring.flash.generated_issued'),
            default => __('invoicing_work.recurring.flash.generated'),
        };
        Inertia::flash('toast', ['type' => $run->error !== null ? 'warning' : 'success', 'message' => $message]);

        return redirect($document->isDraft() ? $document->url().'/editar' : $document->url());
    }

    public function review(Request $request, RecurringInvoice $recurring, RecurringInvoices $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->review($recurring, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.recurring.flash.reviewed')]);

        return back();
    }
}
