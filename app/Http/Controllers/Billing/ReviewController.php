<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\HoldedContactResolver;
use App\Domain\Billing\HoldedInvoiceLinker;
use App\Domain\Billing\ReviewInbox;
use App\Domain\Billing\ReviewUndo;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja «Por revisar» (I5, D-413 y D-414): /facturacion/por-revisar?tipo=contactos|facturas.
 * Aceptar, descartar o elegir otro van por las acciones de siempre (PUT /facturacion/contactos/{id}
 * y POST /facturacion/facturas/{id}/enlaces), que guardan su «Deshacer»; aquí van además «Aceptar
 * las de confianza alta» (fila a fila, con los mismos permisos y la propuesta recalculada en el
 * servidor) y «Deshacer» la última acción. Quién: view-billing (en la ruta). Nunca se crea un
 * cliente desde Holded (D-387).
 */
class ReviewController extends Controller
{
    public function index(Request $request, ReviewInbox $inbox, ReviewUndo $undo): Response
    {
        $counts = ReviewInbox::counts();
        $tab = in_array($request->query('tipo'), ReviewInbox::TABS, true)
            ? (string) $request->query('tipo')
            // Sin tipo, la pestaña que tiene algo (primero los contactos: sin cliente no hay propuesta de proyecto).
            : ($counts['contactos'] === 0 && $counts['facturas'] > 0 ? 'facturas' : 'contactos');

        return Inertia::render('billing/review', [
            'tab' => $tab,
            'counts' => $counts,
            'coverage' => ReviewInbox::coverage(),
            'contacts' => $tab === 'contactos' ? $inbox->contacts() : [],
            'invoices' => $tab === 'facturas' ? $inbox->invoices() : [],
            'clients' => $tab === 'contactos' ? self::clientOptions() : [],
            'targets' => $tab === 'facturas' ? ReviewInbox::linkTargets() : [],
            'undo' => $undo->last(),
        ]);
    }

    /**
     * «Aceptar las de confianza alta» de una pestaña: la propuesta se recalcula aquí y solo se
     * aplican las de confianza alta (una a una, como si se pulsara Aceptar en cada fila).
     */
    public function accept(Request $request, ReviewInbox $inbox, HoldedContactResolver $resolver, HoldedInvoiceLinker $linker, ReviewUndo $undo): RedirectResponse
    {
        $data = $request->validate(['tipo' => ['required', 'string', 'in:contactos,facturas']]);

        /** @var User $user */
        $user = $request->user();
        $snapshots = [];
        $links = [];

        if ($data['tipo'] === 'contactos') {
            foreach ($inbox->highConfidenceContacts() as ['contact' => $contact, 'client_id' => $clientId]) {
                $snapshots[] = $resolver->apply($contact, 'assign', $clientId, $user);
            }
        } else {
            foreach ($inbox->highConfidenceInvoices() as ['invoice' => $invoice, 'project_id' => $projectId, 'bank_id' => $bankId]) {
                $project = Project::query()->whereKey($projectId)->first();
                $bank = $bankId === null ? null : HourBank::query()->whereKey($bankId)->first();
                if ($project === null) {
                    continue;
                }
                $link = $linker->link($invoice, $project, $bank, $user);
                if ($link->wasRecentlyCreated) {
                    $links[] = $link->id;
                }
            }
        }

        $count = count($snapshots) + count($links);
        $message = trans_choice($data['tipo'] === 'contactos' ? 'billing.review.accepted_contacts' : 'billing.review.accepted_invoices', $count, ['count' => $count]);
        $undo->remember($message, $snapshots, $links);
        Inertia::flash('toast', ['type' => $count > 0 ? 'success' : 'info', 'message' => $count > 0 ? $message : __('billing.review.nothing_high')]);

        return back();
    }

    /** Deshace la última acción de la bandeja (D-414). */
    public function undo(HoldedContactResolver $resolver, ReviewUndo $undo): RedirectResponse
    {
        $count = $undo->undo($resolver);
        Inertia::flash('toast', ['type' => $count > 0 ? 'success' : 'info', 'message' => $count > 0 ? trans_choice('billing.review.undone', $count, ['count' => $count]) : __('billing.review.nothing_to_undo')]);

        return back();
    }

    /**
     * @return list<array{id: int, name: string, is_active: bool, tax_id: string|null}>
     */
    public static function clientOptions(): array
    {
        return array_values(Client::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active', 'tax_id'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active, 'tax_id' => $client->tax_id])
            ->all());
    }
}
