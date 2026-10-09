<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una factura de la vista `billing_documents` (PLAN-EMISION §4.6; D-427): las de Holded y las propias
 * juntas, con las columnas de `holded_invoices`. Solo lectura. Las propias llevan el id en negativo
 * (`source` = audax, `source_id` = su id en `sales_documents`); la serie de pruebas no está nunca
 * (salvo en `billing_documents_all`, que solo lee el listado). Las reglas de lo que cuenta son las de
 * D-397, iguales para las dos.
 *
 * @property int $id
 * @property string $source
 * @property int $source_id
 * @property HoldedDocumentKind $kind
 * @property string|null $number
 * @property string|null $number_normalized
 * @property string|null $holded_contact_id
 * @property string|null $contact_name
 * @property int|null $client_id
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property string $currency
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $paid_total
 * @property string $pending_total
 * @property string|null $holded_status
 * @property CollectionStatus $collection_status
 * @property bool $is_draft
 * @property list<string>|null $tags
 * @property int|null $rectified_invoice_id
 * @property string|null $notes
 * @property string|null $pdf_path
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $no_project_needed_at «No necesita proyecto» (D-431), de las dos tablas
 * @property bool $is_test
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 * @property-read BillingDocument|null $rectified
 * @property-read Collection<int, BillingDocumentLine> $lines
 * @property-read Collection<int, BillingDocumentLink> $links
 */
class BillingDocument extends Model
{
    public const string SOURCE_HOLDED = 'holded';

    public const string SOURCE_AUDAX = 'audax';

    protected $table = 'billing_documents';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'source_id' => 'integer',
            'client_id' => 'integer',
            'rectified_invoice_id' => 'integer',
            'kind' => HoldedDocumentKind::class,
            'collection_status' => CollectionStatus::class,
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'pending_total' => 'decimal:2',
            'is_draft' => 'boolean',
            'is_test' => 'boolean',
            'tags' => 'array',
            'synced_at' => 'immutable_datetime',
            'no_project_needed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Incluye la serie de pruebas (solo para el listado y su pestaña «Pruebas»), con el mismo alias
     * de tabla, así las condiciones `billing_documents.*` siguen valiendo.
     *
     * @return Builder<BillingDocument>
     */
    public static function withTests(): Builder
    {
        return self::query()->from('billing_documents_all', 'billing_documents');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<BillingDocument, $this>
     */
    public function rectified(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectified_invoice_id');
    }

    /**
     * @return HasMany<BillingDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingDocumentLine::class, 'document_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<BillingDocumentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(BillingDocumentLink::class, 'document_id')->orderBy('id');
    }

    /** «No necesita proyecto» (D-431), como MarksNoProjectNeeded (la vista no se escribe). */
    public function noProjectNeeded(): bool
    {
        return $this->no_project_needed_at !== null;
    }

    public function isOwn(): bool
    {
        return $this->source === self::SOURCE_AUDAX;
    }

    /** La ficha de una factura de la vista: la de Holded o la propia (id negativo). */
    public static function urlFor(int $id): string
    {
        return $id < 0 ? '/facturacion/documentos/'.(-$id) : '/facturacion/facturas/'.$id;
    }

    /**
     * ¿Cuenta en lo facturado? (D-397, como HoldedInvoice::counts): una factura emitida y no anulada;
     * una rectificativa resta salvo que su factura ya esté anulada.
     */
    public function counts(): bool
    {
        if ($this->is_draft || $this->is_test || in_array($this->collection_status, [CollectionStatus::Cancelled, CollectionStatus::Draft], true)) {
            return false;
        }

        if ($this->kind === HoldedDocumentKind::CreditNote && $this->rectified_invoice_id !== null) {
            $original = $this->rectified;

            return $original === null || $original->collection_status !== CollectionStatus::Cancelled;
        }

        return true;
    }

    /**
     * Lo que cuenta en lo facturado, en SQL (la misma regla que counts()).
     *
     * @param  Builder<BillingDocument>  $query
     * @return Builder<BillingDocument>
     */
    public static function countingIn(Builder $query): Builder
    {
        $cancelled = CollectionStatus::Cancelled->value;

        return $query->where('billing_documents.is_draft', false)
            ->whereNotIn('billing_documents.collection_status', [$cancelled, CollectionStatus::Draft->value])
            ->where(fn (Builder $q) => $q->where('billing_documents.kind', '!=', HoldedDocumentKind::CreditNote->value)
                ->orWhereNull('billing_documents.rectified_invoice_id')
                ->orWhereNotIn('billing_documents.rectified_invoice_id', fn ($sub) => $sub->select('id')->from('billing_documents')->where('collection_status', $cancelled)));
    }
}
