<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Domain\Billing\HoldedInvoiceLinker;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contactos de Holded y su cliente de Audax (Fase 12, D-387): /facturacion/contactos con los que no
 * casan (por defecto), todos o los descartados. Resolver uno es elegir su cliente (sus facturas
 * pasan a ese cliente), descartarlo (no es un cliente de la agencia) o volver a casarlo solo. Nunca
 * se crea un cliente desde aquí.
 */
class HoldedContactController extends Controller
{
    public const array VIEWS = ['sin-casar', 'por-revisar', 'todos', 'descartados'];

    public function index(Request $request): Response
    {
        $view = in_array($request->query('vista'), self::VIEWS, true) ? (string) $request->query('vista') : 'sin-casar';

        $contacts = HoldedContact::query()
            ->with('client:id,name,is_active')
            ->when($view === 'sin-casar', fn (Builder $q) => $q->whereNull('client_id')->whereNull('ignored_at'))
            ->when($view === 'descartados', fn (Builder $q) => $q->whereNotNull('ignored_at'))
            ->when($view === 'por-revisar', fn (Builder $q) => $q->where('match_method', HoldedContact::MATCH_APPROX))
            ->orderBy('name')->orderBy('id')
            ->limit(500)
            ->get();

        $invoices = HoldedInvoice::query()->whereIn('holded_contact_id', $contacts->pluck('holded_id')->all())
            ->selectRaw('holded_contact_id, COUNT(*) as count, COALESCE(SUM(subtotal), 0) as subtotal')
            ->groupBy('holded_contact_id')->get()->keyBy('holded_contact_id');

        return Inertia::render('billing/contacts', [
            'view' => $view,
            'counts' => [
                'sin-casar' => HoldedContact::query()->whereNull('client_id')->whereNull('ignored_at')->count(),
                'por-revisar' => HoldedContact::query()->where('match_method', HoldedContact::MATCH_APPROX)->count(),
                'todos' => HoldedContact::query()->count(),
                'descartados' => HoldedContact::query()->whereNotNull('ignored_at')->count(),
            ],
            // Primero los que más facturan (los que importan para los informes), después por nombre.
            'contacts' => $contacts->sortBy([
                fn (HoldedContact $a, HoldedContact $b): int => (float) ($invoices[$b->holded_id]->subtotal ?? 0) <=> (float) ($invoices[$a->holded_id]->subtotal ?? 0),
                fn (HoldedContact $a, HoldedContact $b): int => strcmp(mb_strtolower($a->name), mb_strtolower($b->name)),
            ])->map(fn (HoldedContact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'trade_name' => $contact->trade_name,
                'tax_id' => $contact->tax_id,
                'email' => $contact->email,
                'city' => $contact->address['city'] ?? null,
                'client' => $contact->client !== null ? ['id' => $contact->client->id, 'name' => $contact->client->name] : null,
                'match_method' => $contact->match_method,
                'ignored' => $contact->ignored_at !== null,
                'invoices' => (int) ($invoices[$contact->holded_id]->count ?? 0),
                'invoiced' => (string) ($invoices[$contact->holded_id]->subtotal ?? '0'),
            ])->values()->all(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'is_active', 'tax_id'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active, 'tax_id' => $client->tax_id])
                ->values()->all(),
        ]);
    }

    public function update(Request $request, HoldedContact $contact, HoldedContactMatcher $matcher, HoldedInvoiceLinker $linker): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:assign,ignore,auto,confirm'],
            'client_id' => ['required_if:action,assign', 'nullable', 'integer', 'exists:clients,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($data, $contact, $matcher, $user): void {
            match ($data['action']) {
                'assign' => $contact->forceFill(['client_id' => (int) $data['client_id'], 'match_method' => HoldedContact::MATCH_MANUAL, 'ignored_at' => null, 'resolved_by' => $user->id]),
                // Da por bueno el cliente de un nombre parecido (D-248): pasa a hecho a mano.
                'confirm' => $contact->forceFill(['match_method' => $contact->client_id !== null ? HoldedContact::MATCH_MANUAL : $contact->match_method, 'resolved_by' => $user->id]),
                'ignore' => $contact->forceFill(['client_id' => null, 'match_method' => null, 'ignored_at' => now(), 'resolved_by' => $user->id]),
                default => $contact->forceFill(['client_id' => null, 'match_method' => null, 'ignored_at' => null, 'resolved_by' => null]),
            };

            if ($data['action'] === 'auto') {
                $matcher->match($contact);
            }
            $contact->save();

            if ($contact->client_id !== null) {
                $matcher->fillClient($contact);
            }

            // Sus facturas pasan al cliente elegido (o se quedan sin cliente).
            HoldedInvoice::query()->where('holded_contact_id', $contact->holded_id)->update(['client_id' => $contact->client_id]);
        });

        $message = match (true) {
            $contact->client_id !== null => __('billing.contacts.assigned', ['contact' => $contact->name, 'client' => (string) Client::query()->withTrashed()->whereKey($contact->client_id)->value('name')]),
            $contact->ignored_at !== null => __('billing.contacts.ignored', ['contact' => $contact->name]),
            default => __('billing.contacts.reset', ['contact' => $contact->name]),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
