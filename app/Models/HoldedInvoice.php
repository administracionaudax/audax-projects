<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura o rectificativa emitida en Holded (Fase 12, D-385), espejo de solo lectura: Holded sigue
 * emitiendo (P1 A). Importes en decimal con signo (las rectificativas, en negativo); el PDF original
 * se guarda en el disco privado (`pdf_path`). Solo la ve quien tiene view-billing.
 *
 * @property int $id
 * @property string $holded_id
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
 * @property string|null $rectified_holded_id
 * @property int|null $rectified_invoice_id
 * @property string|null $notes
 * @property string|null $content_hash
 * @property string|null $pdf_path
 * @property CarbonImmutable|null $pdf_fetched_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 * @property-read HoldedInvoice|null $rectified
 * @property-read Collection<int, HoldedInvoice> $rectifications
 * @property-read Collection<int, HoldedInvoiceLine> $lines
 * @property-read Collection<int, HoldedPayment> $payments
 * @property-read Collection<int, HoldedInvoiceLink> $links
 */
#[Fillable([
    'holded_id',
    'kind',
    'number',
    'number_normalized',
    'holded_contact_id',
    'contact_name',
    'client_id',
    'issued_on',
    'due_on',
    'currency',
    'subtotal',
    'tax_total',
    'total',
    'paid_total',
    'pending_total',
    'holded_status',
    'collection_status',
    'is_draft',
    'tags',
    'rectified_holded_id',
    'rectified_invoice_id',
    'notes',
    'content_hash',
    'pdf_path',
    'pdf_fetched_at',
    'synced_at',
])]
class HoldedInvoice extends Model
{
    /** Disco privado de los PDF originales (D-389). */
    public const string PDF_DISK = 'local';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
            'tags' => 'array',
            'pdf_fetched_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<HoldedInvoice, $this>
     */
    public function rectified(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectified_invoice_id');
    }

    /**
     * @return HasMany<HoldedInvoice, $this>
     */
    public function rectifications(): HasMany
    {
        return $this->hasMany(self::class, 'rectified_invoice_id');
    }

    /**
     * @return HasMany<HoldedInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(HoldedInvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<HoldedPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(HoldedPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * @return HasMany<HoldedInvoiceLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(HoldedInvoiceLink::class)->orderBy('id');
    }

    /** ¿Cuenta como facturado? Aprobada y no anulada (H-037, H-042). */
    public function counts(): bool
    {
        return ! $this->is_draft && ! in_array($this->collection_status, [CollectionStatus::Cancelled, CollectionStatus::Draft], true);
    }

    /** Nombre del PDF al descargarlo: «F260170.pdf». */
    public function pdfFilename(): string
    {
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($this->number ?? $this->holded_id));

        return trim((string) $number, '-').'.pdf';
    }
}
