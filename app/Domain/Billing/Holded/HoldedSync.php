<?php

namespace App\Domain\Billing\Holded;

use App\Domain\Billing\HoldedInvoiceLinker;
use App\Domain\Import\ClickUp\ImportRefs;
use App\Domain\Reports\Money;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedPayment;
use App\Models\HoldedProject;
use App\Models\HoldedSyncRun;
use App\Models\ImportRef;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincronización de SOLO lectura con Holded (Fase 12, F1; D-387): Audax lee y nunca escribe en
 * Holded. Idempotente: cada objeto se busca por su id de Holded (`holded_id` único y `import_refs`
 * con la fuente `holded`), así que repetirla no duplica nada y lo que no cambia no se reescribe.
 *
 * Orden: contactos (y su cliente), proyectos de Holded, facturas y rectificativas con sus líneas,
 * cobros, estado de cobro, enlaces con proyectos y bolsas (HoldedInvoiceLinker) y, al final, los PDF
 * que faltan (como mucho `pdfs_per_run` por ejecución). Los borradores de Holded (los que generan
 * las recurrentes el día 29) se guardan sin número y como «previsto»: nunca cuentan como facturado
 * (H-037, D-395); si desaparecen de Holded, se borran aquí. Un candado impide dos a la vez.
 */
final class HoldedSync
{
    public const string LOCK = 'holded-sync';

    public const string SOURCE = 'holded';

    /** Tipos de contacto que no son clientes (H-002). */
    private const array SKIPPED_CONTACT_TYPES = ['supplier', 'creditor', 'lead'];

    /** @var array<string, int> */
    private array $stats = [];

    /** @var array<string, true> ids de Holded de los documentos leídos en esta ejecución */
    private array $seen = [];

    private ImportRefs $refs;

    public function __construct(
        private readonly HoldedContactMatcher $matcher,
        private readonly HoldedInvoiceLinker $linker,
    ) {
        $this->refs = new ImportRefs(self::SOURCE);
    }

    /**
     * @param  string  $trigger  schedule | manual | command | seeder
     *
     * @throws HoldedSyncBusy si ya hay una en marcha
     */
    public function run(HoldedApi $api, string $trigger, ?User $by = null, bool $pdfs = true): HoldedSyncRun
    {
        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            throw new HoldedSyncBusy((string) __('billing.holded.errors.busy'));
        }

        $run = HoldedSyncRun::query()->create([
            'trigger' => $trigger,
            'user_id' => $by?->id,
            'status' => HoldedSyncRun::RUNNING,
            'started_at' => now(),
        ]);

        try {
            $this->stats = [];
            $this->seen = [];
            $this->refs->load();
            $this->matcher->reset();
            $this->linker->reset();
            $today = LocalTime::today();

            $this->contacts($api);
            $this->projects($api);
            $this->documents($api->invoices(), HoldedDocumentKind::Invoice);
            $this->documents($api->creditNotes(), HoldedDocumentKind::CreditNote);
            $this->forgetVanishedDrafts();
            $this->resolveRectified();
            $this->payments($api);
            $this->collectionStatus($today);
            $this->stats['linked_invoices'] = $this->linker->relink();
            $this->stats['unlinked_invoices'] = HoldedInvoice::query()->whereDoesntHave('links')->count();
            if ($pdfs) {
                $this->pdfs($api);
            }
            $this->stats['requests'] = $api->requestCount();

            $run->forceFill(['status' => HoldedSyncRun::OK, 'stats' => $this->stats, 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            // Sin la clave: HoldedRequestFailed nunca la lleva; cualquier otro error, solo su mensaje.
            $message = $e instanceof HoldedRequestFailed ? $e->getMessage() : Str::limit($e->getMessage(), 500);
            $run->forceFill(['status' => HoldedSyncRun::FAILED, 'stats' => $this->stats, 'error' => $message, 'finished_at' => now()])->save();
            Log::warning('Sincronización con Holded fallida', ['run' => $run->id, 'error' => $message]);

            throw $e;
        } finally {
            $lock->release();
        }

        return $run;
    }

    /** ¿Hay una sincronización en marcha? */
    public static function busy(): bool
    {
        $lock = Cache::lock(self::LOCK, 1);

        try {
            if ($lock->get()) {
                $lock->release();

                return false;
            }
        } catch (LockTimeoutException) {
            return true;
        }

        return true;
    }

    private function contacts(HoldedApi $api): void
    {
        foreach ($api->contacts() as $item) {
            $id = HoldedPayload::id($item);
            $type = HoldedPayload::string($item, 'type');
            if ($id === null || in_array(strtolower((string) $type), self::SKIPPED_CONTACT_TYPES, true)) {
                continue;
            }

            $taxId = HoldedPayload::string($item, 'tax_id', 'code', 'vat_number', 'vatnumber', 'nif');
            $contact = HoldedContact::query()->firstOrNew(['holded_id' => $id]);
            $contact->fill([
                'name' => Str::limit(HoldedPayload::string($item, 'name') ?? HoldedPayload::string($item, 'trade_name', 'tradeName') ?? $id, 250, ''),
                'trade_name' => self::limit(HoldedPayload::string($item, 'trade_name', 'tradeName'), 250),
                'tax_id' => self::limit($taxId, 32),
                'tax_id_normalized' => self::limit(HoldedPayload::normalizeTaxId($taxId), 32),
                'email' => self::limit(HoldedPayload::string($item, 'email'), 250),
                'type' => self::limit($type, 24),
                'country_code' => self::countryCode(HoldedPayload::string($item, 'billing_address.country_code', 'billAddress.countryCode', 'country_code', 'countryCode')),
                'address' => array_filter([
                    'address' => HoldedPayload::string($item, 'billing_address.address', 'billAddress.address', 'address'),
                    'city' => HoldedPayload::string($item, 'billing_address.city', 'billAddress.city', 'city'),
                    'postal_code' => HoldedPayload::string($item, 'billing_address.postal_code', 'billAddress.postalCode', 'postal_code'),
                    'province' => HoldedPayload::string($item, 'billing_address.province', 'billAddress.province', 'province'),
                ], fn (?string $value): bool => $value !== null) ?: null,
            ]);

            $this->matcher->match($contact);
            $contact->synced_at = now();
            $changed = $contact->isDirty(['name', 'trade_name', 'tax_id', 'email', 'type', 'country_code', 'address', 'client_id', 'match_method']);
            $created = ! $contact->exists;
            $contact->save();
            $this->matcher->fillClient($contact);

            $this->refs->put('contact', $id, 'holded_contact', $contact->id);
            $this->count('contacts', $created ? 'created' : ($changed ? 'updated' : 'unchanged'));
        }

        $this->stats['contacts_unresolved'] = HoldedContact::query()->whereNull('client_id')->whereNull('ignored_at')->count();
    }

    private function projects(HoldedApi $api): void
    {
        $codes = Project::query()->whereNotNull('client_id')->orderBy('id')->pluck('id', 'code')->all();

        foreach ($api->projects() as $item) {
            $id = HoldedPayload::id($item);
            if ($id === null) {
                continue;
            }

            $project = HoldedProject::query()->firstOrNew(['holded_id' => $id]);
            $project->name = Str::limit(HoldedPayload::string($item, 'name') ?? $id, 250, '');
            if (! $project->linked_manually) {
                $project->project_id = self::projectByCode($project->name, $codes);
            }
            $project->synced_at = now();
            $project->save();

            $this->refs->put('project', $id, 'holded_project', $project->id);
            $this->count('projects', 'read');
        }
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $items
     */
    private function documents(iterable $items, HoldedDocumentKind $kind): void
    {
        $clients = HoldedContact::query()->whereNotNull('client_id')->pluck('client_id', 'holded_id')->all();
        $label = $kind === HoldedDocumentKind::Invoice ? 'invoices' : 'credit_notes';

        foreach ($items as $item) {
            $id = HoldedPayload::id($item);
            $draft = HoldedPayload::bool($item, 'draft') === true || HoldedPayload::string($item, 'approval_status', 'approvalStatus') === 'draft';
            $date = HoldedPayload::date($item, 'date', 'issued_at');
            if ($id === null || $date === null) {
                $this->count($label, 'skipped');

                continue;
            }
            $this->seen[$id] = true;

            // Una rectificativa es de la serie «CN» (CN250004) aunque la API la dé como otro tipo (D-397).
            $number = $draft ? null : HoldedPayload::string($item, 'document_number', 'docNumber', 'number');
            $docKind = self::isCreditNoteNumber($number) ? HoldedDocumentKind::CreditNote : $kind;
            $sign = $docKind === HoldedDocumentKind::CreditNote ? '-' : '';
            $subtotal = self::signed(HoldedPayload::money($item, 'subtotal', 'sub_total') ?? '0.00', $sign);
            $tax = self::signed(HoldedPayload::money($item, 'tax', 'tax_total', 'taxes_total') ?? '0.00', $sign);
            $total = self::signed(HoldedPayload::money($item, 'total') ?? Money::round(Money::add($subtotal, $tax)), $sign);
            $contactId = HoldedPayload::string($item, 'contact_id', 'contact', 'contact.id');
            // Un borrador no tiene número (Holded enseña «Borrador»): se numera al aprobarlo.
            $lines = $this->lines($item);

            $attributes = [
                'kind' => $docKind,
                'number' => self::limit($number, 64),
                'number_normalized' => self::limit(HoldedPayload::normalizeNumber($number), 64),
                'holded_contact_id' => self::limit($contactId, 64),
                'contact_name' => self::limit(HoldedPayload::string($item, 'contact_name', 'contactName', 'contact.name'), 250),
                'client_id' => $contactId !== null ? ($clients[$contactId] ?? null) : null,
                'issued_on' => $date->toDateString(),
                'due_on' => HoldedPayload::date($item, 'due_date', 'dueDate')?->toDateString(),
                'currency' => strtoupper(substr(HoldedPayload::string($item, 'currency') ?? 'EUR', 0, 3)),
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'total' => $total,
                'paid_total' => self::signed(HoldedPayload::money($item, 'payments_total', 'paymentsTotal') ?? '0.00', $sign),
                'pending_total' => self::signed(HoldedPayload::money($item, 'payments_pending', 'paymentsPending') ?? Money::round(Money::abs($total)), $sign),
                'holded_status' => self::limit(HoldedPayload::string($item, 'status'), 24),
                'is_draft' => $draft,
                'tags' => array_values(array_filter(array_map(fn (mixed $tag): ?string => is_string($tag) && trim($tag) !== '' ? Str::limit(trim($tag), 60, '') : null, is_array($item['tags'] ?? null) ? $item['tags'] : []))) ?: null,
                'rectified_holded_id' => self::limit(HoldedPayload::string($item, 'rectified_document_id', 'rectified_invoice_id', 'original_document_id', 'from.id', 'from_id', 'invoice_id'), 64),
                'notes' => HoldedPayload::string($item, 'notes', 'body'),
            ];
            $hash = hash('sha256', (string) json_encode([$draft, $attributes['tags'], $attributes['number'], $attributes['issued_on'], $attributes['due_on'], $subtotal, $tax, $total, $attributes['holded_contact_id'], $attributes['rectified_holded_id'], $attributes['notes'], $lines]));

            $invoice = HoldedInvoice::query()->firstOrNew(['holded_id' => $id]);
            $created = ! $invoice->exists;
            $contentChanged = $invoice->content_hash !== $hash;
            $invoice->fill($attributes);
            $invoice->content_hash = $hash;
            if ($contentChanged && ! $created) {
                // El documento cambió en Holded (p. ej. se anuló): su PDF se vuelve a descargar.
                $invoice->pdf_path = null;
                $invoice->pdf_fetched_at = null;
            }
            $dirty = $invoice->isDirty();
            if ($dirty) {
                $invoice->synced_at = now();
            }
            $invoice->save();

            if ($created || $contentChanged) {
                $this->replaceLines($invoice, $lines);
            }

            $this->refs->put($docKind === HoldedDocumentKind::Invoice ? 'invoice' : 'credit_note', $id, 'holded_invoice', $invoice->id);
            $this->count($draft ? 'drafts' : $label, $created ? 'created' : ($dirty ? 'updated' : 'unchanged'));
        }
    }

    /** Los borradores que ya no están en Holded (se borraron allí): fuera, con sus líneas y enlaces. */
    private function forgetVanishedDrafts(): void
    {
        $vanished = HoldedInvoice::query()->where('is_draft', true)->pluck('holded_id', 'id')
            ->filter(fn (string $holdedId): bool => ! isset($this->seen[$holdedId]));

        if ($vanished->isEmpty()) {
            return;
        }

        HoldedInvoice::query()->whereKey($vanished->keys()->all())->delete();
        ImportRef::query()->where('source', self::SOURCE)->whereIn('kind', ['invoice', 'credit_note'])->whereIn('external_id', $vanished->values()->all())->delete();
        $this->refs->load();
        $this->stats['drafts_removed'] = $vanished->count();
    }

    /**
     * Líneas normalizadas de un documento.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function lines(array $item): array
    {
        $lines = [];
        foreach (HoldedPayload::list($item, 'items', 'products', 'lines') as $index => $line) {
            if (strtolower((string) HoldedPayload::string($line, 'type')) === 'title') {
                continue;
            }
            $units = HoldedPayload::decimal($line, 4, 'units', 'quantity') ?? '1.0000';
            $price = HoldedPayload::decimal($line, 4, 'price', 'unit_price') ?? '0.0000';
            $discount = HoldedPayload::decimal($line, 2, 'discount') ?? '0.00';
            $subtotal = HoldedPayload::money($line, 'subtotal', 'sub_total')
                ?? Money::round(Money::mul(Money::mul($units, $price), Money::sub('1', Money::div($discount, '100'))));

            $lines[] = [
                'position' => $index + 1,
                'name' => self::limit(HoldedPayload::string($line, 'name', 'service_name', 'service.name'), 250),
                'service_code' => self::limit(HoldedPayload::string($line, 'code', 'sku', 'service_code', 'service.code'), 32),
                'description' => HoldedPayload::string($line, 'description', 'desc'),
                'units' => $units,
                'unit_price' => $price,
                'discount_pct' => $discount,
                'subtotal' => $subtotal,
                'tax_rate' => HoldedPayload::taxRate($line),
                'holded_project_id' => self::limit(HoldedPayload::string($line, 'project_id', 'projectId', 'projectid'), 64),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(HoldedInvoice $invoice, array $lines): void
    {
        DB::transaction(function () use ($invoice, $lines): void {
            HoldedInvoiceLine::query()->where('holded_invoice_id', $invoice->id)->delete();
            $now = now();
            foreach ($lines as $line) {
                HoldedInvoiceLine::query()->insert([...$line, 'holded_invoice_id' => $invoice->id, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    /** Rectificativa → la factura que rectifica, por su id de Holded. */
    private function resolveRectified(): void
    {
        $ids = HoldedInvoice::query()->pluck('id', 'holded_id')->all();

        HoldedInvoice::query()->whereNotNull('rectified_holded_id')->orderBy('id')->each(function (HoldedInvoice $credit) use ($ids): void {
            $original = $ids[(string) $credit->rectified_holded_id] ?? null;
            if ($credit->rectified_invoice_id !== $original) {
                $credit->forceFill(['rectified_invoice_id' => $original])->save();
            }
        });
    }

    /**
     * Los cobros sueltos piden otro permiso de Holded (`accounting:payments.read`). Sin él se sigue:
     * lo cobrado y lo pendiente ya vienen en cada factura, y la ejecución lo apunta en sus datos.
     */
    private function payments(HoldedApi $api): void
    {
        try {
            $this->readPayments($api);
        } catch (HoldedRequestFailed $e) {
            if ($e->status !== 403) {
                throw $e;
            }
            $this->stats['payments_forbidden'] = 1;
        }
    }

    private function readPayments(HoldedApi $api): void
    {
        $invoices = HoldedInvoice::query()->pluck('id', 'holded_id')->all();

        foreach ($api->payments() as $item) {
            $id = HoldedPayload::id($item);
            $date = HoldedPayload::date($item, 'date', 'paid_at');
            $amount = HoldedPayload::money($item, 'amount');
            if ($id === null || $date === null || $amount === null) {
                $this->count('payments', 'skipped');

                continue;
            }

            $documentId = HoldedPayload::string($item, 'document_id', 'documentId', 'invoice_id', 'document.id');
            $payment = HoldedPayment::query()->firstOrNew(['holded_id' => $id]);
            $created = ! $payment->exists;
            $payment->fill([
                'holded_document_id' => self::limit($documentId, 64),
                'holded_invoice_id' => $documentId !== null ? ($invoices[$documentId] ?? null) : null,
                'paid_on' => $date->toDateString(),
                'amount' => $amount,
                'method' => self::limit(HoldedPayload::string($item, 'payment_method', 'paymentMethod', 'method', 'payment_method.name'), 64),
                'description' => self::limit(HoldedPayload::string($item, 'description', 'desc'), 250),
            ]);
            $dirty = $payment->isDirty();
            if ($dirty) {
                $payment->synced_at = now();
            }
            $payment->save();

            $this->refs->put('payment', $id, 'holded_payment', $payment->id);
            $this->count('payments', $created ? 'created' : ($dirty ? 'updated' : 'unchanged'));
        }
    }

    /**
     * Estado de cobro de cada factura: anulada (estado de Holded), cobrada, a medias, vencida o
     * pendiente. Si Holded no da lo cobrado, sale de sus cobros.
     */
    private function collectionStatus(CarbonImmutable $today): void
    {
        $paidByInvoice = HoldedPayment::query()->whereNotNull('holded_invoice_id')
            ->selectRaw('holded_invoice_id, SUM(amount) as paid')->groupBy('holded_invoice_id')
            ->pluck('paid', 'holded_invoice_id')->all();

        HoldedInvoice::query()->orderBy('id')->each(function (HoldedInvoice $invoice) use ($paidByInvoice, $today): void {
            $total = Money::abs((string) $invoice->total);
            $paid = Money::abs((string) $invoice->paid_total);
            $fromPayments = isset($paidByInvoice[$invoice->id]) ? Money::abs(Money::of((string) $paidByInvoice[$invoice->id])) : '0';

            if (Money::isZero($paid) && ! Money::isZero($fromPayments)) {
                $paid = $fromPayments;
            }
            $pending = Money::abs((string) $invoice->pending_total);
            if (Money::isZero($pending) && bccomp($paid, $total, 2) < 0 && ! in_array($invoice->holded_status, ['completed', 'paid'], true)) {
                $pending = Money::sub($total, $paid);
            }

            $status = match (true) {
                $invoice->is_draft => CollectionStatus::Draft,
                // Una rectificativa sale «Anulado» en Holded cuando anula su factura: ella misma cuenta
                // (resta), salvo que su original ya esté anulada (HoldedInvoice::counts, D-397).
                $invoice->kind === HoldedDocumentKind::CreditNote => Money::isZero($pending) ? CollectionStatus::Paid : CollectionStatus::Unpaid,
                in_array($invoice->holded_status, ['cancelled', 'canceled', 'void'], true) => CollectionStatus::Cancelled,
                Money::isZero($pending) => CollectionStatus::Paid,
                $invoice->due_on !== null && $invoice->due_on->lessThan($today) => CollectionStatus::Overdue,
                ! Money::isZero($paid) => CollectionStatus::Partial,
                default => CollectionStatus::Unpaid,
            };

            $sign = $invoice->kind === HoldedDocumentKind::CreditNote ? '-' : '';
            $invoice->fill([
                'paid_total' => self::signed(Money::round($paid), $sign),
                'pending_total' => self::signed(Money::round($pending), $sign),
                'collection_status' => $status,
            ]);
            if ($invoice->isDirty()) {
                $invoice->save();
            }
        });
    }

    /** Los PDF que faltan, en el disco privado (D-389). Un fallo en uno no para la sincronización. */
    private function pdfs(HoldedApi $api): void
    {
        $limit = max(0, (int) config('services.holded.pdfs_per_run', 200));

        $missing = HoldedInvoice::query()->whereNull('pdf_path')->where('is_draft', false)->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get();
        foreach ($missing as $invoice) {
            try {
                HoldedPdfStore::fetch($api, $invoice);
                $this->count('pdfs', 'stored');
            } catch (HoldedRequestFailed $e) {
                $this->count('pdfs', 'failed');
                Log::notice('PDF de Holded no descargado', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        $this->stats['pdfs_pending'] = HoldedInvoice::query()->whereNull('pdf_path')->where('is_draft', false)->count();
    }

    private function count(string $group, string $what): void
    {
        $key = $group.'_'.$what;
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    /**
     * Proyecto de Audax cuyo código aparece en el nombre del proyecto de Holded («ACME-FE1 · …»);
     * el más largo si hay varios (ACME-FE10 antes que ACME-FE1).
     *
     * @param  array<string, int>  $codes  código → id
     */
    public static function projectByCode(string $name, array $codes): ?int
    {
        $upper = strtoupper($name);
        $best = null;

        foreach ($codes as $code => $id) {
            $code = (string) $code;
            if ($code === '' || preg_match('/(^|[^A-Z0-9-])'.preg_quote($code, '/').'($|[^A-Z0-9-])/', $upper) !== 1) {
                continue;
            }
            if ($best === null || strlen($code) > strlen($best[0])) {
                $best = [$code, $id];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * @return numeric-string
     */
    private static function signed(string $amount, string $sign): string
    {
        $abs = Money::round(Money::abs($amount));

        return $sign === '-' && ! Money::isZero($abs) ? Money::round('-'.$abs) : $abs;
    }

    /** ¿Es de la serie de rectificativas (CN + año + número)? */
    public static function isCreditNoteNumber(?string $number): bool
    {
        return $number !== null && preg_match('/^CN\d/i', trim($number)) === 1;
    }

    private static function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : Str::limit($value, $length, '');
    }

    private static function countryCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return strlen($value) === 2 ? $value : null;
    }
}
