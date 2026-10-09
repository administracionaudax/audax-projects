<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\InvoiceLinkMethod;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja «Por revisar» (I5, D-413): una sola pantalla para el trabajo de emparejar.
 * - **Contactos**: los de Holded sin casar (ni descartados) y los casados por un nombre parecido,
 *   por confirmar (D-248), cada uno con la propuesta de HoldedContactMatcher::propose (su motivo y
 *   su confianza).
 * - **Facturas sin proyecto ni bolsa**: las de la vista «Sin proyecto» del listado (D-406), cada una
 *   con la mejor sugerencia de InvoiceLinkSuggester::propose.
 * Primero lo que más importe tiene. Lo de Ajustes («Contactos de Holded», el directorio con todos y
 * los descartados) sale de directory(). Un número fijo de consultas, sin N+1.
 */
final class ReviewInbox
{
    /** Filas como mucho por pestaña (las de más importe primero). */
    public const int LIMIT = 300;

    public const array TABS = ['contactos', 'facturas'];

    public function __construct(
        private readonly HoldedContactMatcher $matcher,
        private readonly InvoiceLinkSuggester $suggester,
    ) {}

    /**
     * Contactos pendientes y facturas sin proyecto (en una consulta cada número).
     *
     * @return array{contactos: int, facturas: int}
     */
    public static function counts(): array
    {
        $contacts = HoldedContact::query()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN client_id IS NULL AND ignored_at IS NULL THEN 1 WHEN match_method = ? THEN 1 ELSE 0 END), 0) as review', [HoldedContact::MATCH_APPROX])
            ->first();

        return [
            'contactos' => (int) ($contacts->review ?? 0),
            'facturas' => self::unlinked()->count(),
        ];
    }

    /**
     * Las facturas sin proyecto ni bolsa: como la vista «Sin proyecto» del listado (sin anuladas;
     * también los borradores), sin las marcadas «No necesita proyecto» (D-431).
     *
     * @return Builder<HoldedInvoice>
     */
    public static function unlinked(): Builder
    {
        return HoldedInvoice::whereProjectNeeded(HoldedInvoice::query()
            ->where('holded_invoices.collection_status', '!=', CollectionStatus::Cancelled->value)
            ->whereNotExists(fn (QueryBuilder $links) => $links->selectRaw('1')->from('holded_invoice_links')
                ->whereColumn('holded_invoice_links.holded_invoice_id', 'holded_invoices.id')));
    }

    /** Facturas sin anular marcadas «No necesita proyecto» (D-431), para enlazar a su filtro. */
    public static function noProjectNeededCount(): int
    {
        return HoldedInvoice::query()
            ->where('collection_status', '!=', CollectionStatus::Cancelled->value)
            ->whereNotNull('no_project_needed_at')
            ->count();
    }

    /**
     * Cobertura de la cabecera: contactos casados de los que no están descartados y facturas con
     * proyecto de las que no están anuladas ni marcadas «No necesita proyecto» (D-431).
     *
     * @return array{contacts_matched: int, contacts_total: int, invoices_linked: int, invoices_total: int}
     */
    public static function coverage(): array
    {
        $contacts = HoldedContact::query()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN ignored_at IS NULL THEN 1 ELSE 0 END), 0) as total,'
                .' COALESCE(SUM(CASE WHEN ignored_at IS NULL AND client_id IS NOT NULL AND (match_method IS NULL OR match_method <> ?) THEN 1 ELSE 0 END), 0) as matched', [HoldedContact::MATCH_APPROX])
            ->first();
        $invoices = HoldedInvoice::query()->toBase()
            ->where('collection_status', '!=', CollectionStatus::Cancelled->value)
            // Las marcadas sin enlace no cuentan (si se enlazan después, cuentan como enlazadas).
            ->where(fn (QueryBuilder $q) => $q->whereNull('no_project_needed_at')
                ->orWhereExists(fn (QueryBuilder $links) => $links->selectRaw('1')->from('holded_invoice_links')
                    ->whereColumn('holded_invoice_links.holded_invoice_id', 'holded_invoices.id')))
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM holded_invoice_links WHERE holded_invoice_links.holded_invoice_id = holded_invoices.id) THEN 1 ELSE 0 END), 0) as linked')
            ->first();

        return [
            'contacts_matched' => (int) ($contacts->matched ?? 0),
            'contacts_total' => (int) ($contacts->total ?? 0),
            'invoices_linked' => (int) ($invoices->linked ?? 0),
            'invoices_total' => (int) ($invoices->total ?? 0),
        ];
    }

    /**
     * Los contactos por revisar con su propuesta, primero los que más facturan.
     *
     * @return list<array<string, mixed>>
     */
    public function contacts(): array
    {
        [$contacts, $proposals] = $this->contactProposals();

        $names = Client::query()->withTrashed()
            ->whereIn('id', array_values(array_unique(array_filter(array_map(fn (?array $proposal): ?int => $proposal['client_id'] ?? null, $proposals)))))
            ->orderBy('id')->get(['id', 'name'])->pluck('name', 'id');

        return array_values($contacts->map(function (HoldedContact $contact) use ($proposals, $names): array {
            $proposal = $proposals[$contact->id] ?? null;

            return [
                ...self::contactRow($contact),
                'proposal' => $proposal === null || ! isset($names[$proposal['client_id']]) ? null : [
                    'client' => ['id' => $proposal['client_id'], 'name' => (string) $names[$proposal['client_id']]],
                    'reason' => $proposal['reason'],
                    'confidence' => $proposal['confidence'],
                ],
            ];
        })->all());
    }

    /**
     * Los contactos sin casar con una propuesta de confianza alta y su cliente («Aceptar las de
     * confianza alta», recalculado en el servidor).
     *
     * @return list<array{contact: HoldedContact, client_id: int}>
     */
    public function highConfidenceContacts(): array
    {
        [$contacts, $proposals] = $this->contactProposals();
        $accepted = [];
        foreach ($contacts as $contact) {
            $proposal = $proposals[$contact->id] ?? null;
            if ($proposal !== null && $proposal['confidence'] === 'alta' && $contact->isUnresolved()) {
                $accepted[] = ['contact' => $contact, 'client_id' => $proposal['client_id']];
            }
        }

        return $accepted;
    }

    /**
     * Las facturas sin proyecto con una propuesta de confianza alta, con su proyecto y su bolsa.
     *
     * @return list<array{invoice: HoldedInvoice, project_id: int, bank_id: int|null}>
     */
    public function highConfidenceInvoices(): array
    {
        $accepted = [];
        foreach ($this->invoiceProposals() as [$invoice, $proposal]) {
            if ($proposal['suggestion'] !== null && $proposal['confidence'] === 'alta') {
                $accepted[] = ['invoice' => $invoice, 'project_id' => $proposal['suggestion']['project']['id'], 'bank_id' => $proposal['suggestion']['bank']['id'] ?? null];
            }
        }

        return $accepted;
    }

    /**
     * Los contactos por revisar (los que más facturan primero) y la propuesta de cada uno.
     *
     * @return array{0: Collection<int, HoldedContact>, 1: array<int, array{client_id: int, reason: string, confidence: string}|null>}
     */
    private function contactProposals(): array
    {
        $contacts = $this->contactQuery()
            ->where(fn (Builder $q) => $q->where(fn (Builder $open) => $open->whereNull('holded_contacts.client_id')->whereNull('holded_contacts.ignored_at'))
                ->orWhere('holded_contacts.match_method', HoldedContact::MATCH_APPROX))
            ->limit(self::LIMIT)
            ->get();

        $linked = $this->linkedClients($contacts);
        $proposals = [];
        foreach ($contacts as $contact) {
            $proposals[$contact->id] = $this->matcher->propose($contact, $linked[$contact->holded_id] ?? []);
        }

        return [$contacts, $proposals];
    }

    /**
     * Las facturas sin proyecto (las de más importe primero) y la propuesta de cada una.
     *
     * @return list<array{0: HoldedInvoice, 1: array{suggestion: array{project: array{id: int, code: string, name: string}, bank: array{id: int, name: string}|null, reason: string}|null, alternatives: list<array{project: array{id: int, code: string, name: string}, bank: array{id: int, name: string}|null, reason: string}>, confidence: string|null, dated: bool}}>
     */
    private function invoiceProposals(): array
    {
        $invoices = self::unlinked()
            ->with(['client:id,name', 'lines'])
            ->orderByRaw('ABS(holded_invoices.subtotal) DESC')
            ->orderByDesc('holded_invoices.issued_on')
            ->orderByDesc('holded_invoices.id')
            ->limit(self::LIMIT)
            ->get();

        $this->suggester->prime($invoices);

        return array_values($invoices->map(fn (HoldedInvoice $invoice): array => [$invoice, $this->suggester->propose($invoice)])->all());
    }

    /**
     * El directorio de Ajustes («Contactos de Holded», D-413): todos los contactos, primero los que
     * más facturan, con su cliente y cómo se casó.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(): array
    {
        return array_values($this->contactQuery()->limit(2000)->get()
            ->map(fn (HoldedContact $contact): array => self::contactRow($contact))->all());
    }

    /**
     * Las facturas sin proyecto ni bolsa con su propuesta, primero las de más importe.
     *
     * @return list<array<string, mixed>>
     */
    public function invoices(): array
    {
        return array_map(function (array $pair): array {
            [$invoice, $proposal] = $pair;

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'kind' => $invoice->kind->value,
                'is_draft' => $invoice->is_draft || $invoice->collection_status === CollectionStatus::Draft,
                'issued_on' => $invoice->issued_on->toDateString(),
                'client' => $invoice->client === null ? null : ['id' => $invoice->client->id, 'name' => $invoice->client->name],
                'contact_name' => $invoice->contact_name,
                'subtotal' => (string) $invoice->subtotal,
                'service' => InvoiceLinkSuggester::dominantKind($invoice)->value,
                'proposal' => $proposal['suggestion'] === null ? null : [
                    ...$proposal['suggestion'],
                    'confidence' => $proposal['confidence'],
                    'dated' => $proposal['dated'],
                ],
                'alternatives' => $proposal['alternatives'],
            ];
        }, $this->invoiceProposals());
    }

    /**
     * Proyectos (y sus bolsas) con los que enlazar a mano desde la bandeja: todos los que tienen
     * cliente, sin internos ni archivados (dos consultas para toda la página).
     *
     * @return list<array{id: int, code: string, name: string, client_id: int|null, client: string|null, uses_banks: bool, banks: list<array{id: int, name: string, start_date: string}>}>
     */
    public static function linkTargets(): array
    {
        $projects = Project::query()
            ->whereNotNull('client_id')
            ->where('billing_type', '!=', BillingType::Internal->value)
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->with(['client:id,name'])
            ->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'name', 'client_id', 'billing_type']);

        $banks = HourBank::query()
            ->whereIn('project_id', $projects->filter(fn (Project $project): bool => $project->usesHourBanks())->modelKeys())
            ->orderByDesc('start_date')->orderBy('id')
            ->get(['id', 'name', 'start_date', 'project_id'])
            ->groupBy('project_id');

        return array_values($projects->map(fn (Project $project): array => [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'client' => $project->client?->name,
            'uses_banks' => $project->usesHourBanks(),
            'banks' => array_values(($banks->get($project->id) ?? collect())
                ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name, 'start_date' => $bank->start_date->toDateString()])->all()),
        ])->all());
    }

    /**
     * Contactos con lo que han facturado (número y base) en la misma consulta, de más a menos.
     *
     * @return Builder<HoldedContact>
     */
    private function contactQuery(): Builder
    {
        $invoiced = HoldedInvoice::query()->toBase()
            ->whereNotNull('holded_contact_id')
            ->selectRaw('holded_contact_id, COUNT(*) as invoices_count, COALESCE(SUM(CAST(ROUND(subtotal * 100) AS BIGINT)), 0) as invoiced_cents')
            ->groupBy('holded_contact_id');

        return HoldedContact::query()
            ->with('client:id,name,is_active')
            ->leftJoinSub($invoiced, 'invoiced', 'invoiced.holded_contact_id', '=', 'holded_contacts.holded_id')
            ->select('holded_contacts.*')
            ->selectRaw('COALESCE(invoiced.invoices_count, 0) as invoices_count, COALESCE(invoiced.invoiced_cents, 0) as invoiced_cents')
            ->orderByRaw('COALESCE(invoiced.invoiced_cents, 0) DESC')
            ->orderBy('holded_contacts.name')
            ->orderBy('holded_contacts.id');
    }

    /**
     * Clientes de los proyectos con los que ya están enlazadas las facturas de cada contacto (por el
     * código F, el proyecto de Holded o a mano), en una consulta: contacto => [cliente => motivo].
     *
     * @param  Collection<int, HoldedContact>  $contacts
     * @return array<string, array<int, string>>
     */
    private function linkedClients(Collection $contacts): array
    {
        $unmatched = $contacts->filter(fn (HoldedContact $contact): bool => $contact->client_id === null)->pluck('holded_id')->all();
        if ($unmatched === []) {
            return [];
        }

        $rows = DB::table('holded_invoices')
            ->join('holded_invoice_links', 'holded_invoice_links.holded_invoice_id', '=', 'holded_invoices.id')
            ->join('projects', 'projects.id', '=', 'holded_invoice_links.project_id')
            ->whereIn('holded_invoices.holded_contact_id', $unmatched)
            ->whereNotNull('projects.client_id')
            ->selectRaw('holded_invoices.holded_contact_id as contact, projects.client_id as client_id,'
                .' MAX(CASE WHEN holded_invoice_links.method = ? THEN 1 ELSE 0 END) as by_code', [InvoiceLinkMethod::FCode->value])
            ->groupBy('holded_invoices.holded_contact_id', 'projects.client_id')
            ->orderBy('holded_invoices.holded_contact_id')
            ->orderBy('projects.client_id')
            ->get();

        $linked = [];
        foreach ($rows as $row) {
            $linked[(string) $row->contact][(int) $row->client_id] = (int) $row->by_code === 1 ? 'codigo_f' : 'proyecto';
        }

        return $linked;
    }

    /**
     * @return array<string, mixed>
     */
    private static function contactRow(HoldedContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'trade_name' => $contact->trade_name,
            'tax_id' => $contact->tax_id,
            'email' => $contact->email,
            'city' => $contact->address['city'] ?? null,
            'client' => $contact->client !== null ? ['id' => $contact->client->id, 'name' => $contact->client->name] : null,
            'match_method' => $contact->match_method,
            'ignored' => $contact->ignored_at !== null,
            'invoices' => (int) $contact->getAttribute('invoices_count'),
            'invoiced' => InvoicingReport::money((int) $contact->getAttribute('invoiced_cents')),
        ];
    }
}
