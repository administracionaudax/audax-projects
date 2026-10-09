<?php

namespace App\Models;

use App\Enums\RectificationKind;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Models\Concerns\MarksNoProjectNeeded;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Factura o rectificativa emitida desde Audax (PLAN-EMISION §4.2, E1; D-417 a D-429). Se crea como
 * borrador (sin número); al emitirla (InvoiceIssuer) recibe serie, año y número, las copias del
 * emisor y del cliente, el desglose de impuestos, su registro encadenado y su PDF archivado, y desde
 * ese momento la base de datos no deja cambiar nada fiscal (InvoicingGuards). Una emitida solo se
 * rectifica o se anula (D-244). Importes en decimal con signo (las rectificativas, en negativo).
 *
 * @property int $id
 * @property string $uuid
 * @property SalesDocumentType $type
 * @property SalesDocumentStatus $status
 * @property bool $is_test
 * @property int|null $series_id
 * @property int|null $year
 * @property int|null $number
 * @property string|null $full_number
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable|null $operation_date
 * @property CarbonImmutable|null $due_date
 * @property int $client_id
 * @property string $client_name
 * @property array<string, mixed>|null $client_snapshot
 * @property array<string, mixed>|null $issuer_snapshot
 * @property string $language
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $withholding_total
 * @property string $total
 * @property string $paid_total
 * @property int|null $withholding_rate_id
 * @property string|null $withholding_rate
 * @property string|null $body
 * @property string|null $internal_note
 * @property string|null $customer_reference
 * @property int|null $payment_method_id
 * @property string|null $payment_text
 * @property int|null $rectified_document_id
 * @property int|null $rectified_holded_invoice_id
 * @property RectificationKind|null $rectification_kind
 * @property string|null $rectification_reason
 * @property string|null $rectification_code
 * @property int|null $cancelled_by_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int|null $source_document_id
 * @property int|null $source_holded_invoice_id
 * @property CarbonImmutable|null $issued_at
 * @property int|null $issued_by
 * @property int|null $invoice_record_id
 * @property string|null $pdf_path
 * @property string|null $pdf_sha256
 * @property CarbonImmutable|null $pdf_generated_at
 * @property CarbonImmutable|null $no_project_needed_at «No necesita proyecto» (D-431): no es fiscal
 * @property int|null $no_project_needed_by
 * @property string|null $no_project_note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read NumberingSeries|null $series
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read Collection<int, SalesDocumentLine> $lines
 * @property-read Collection<int, SalesDocumentTax> $taxes
 * @property-read Collection<int, SalesDocumentLink> $links
 * @property-read Collection<int, InvoiceRecord> $records
 * @property-read InvoiceRecord|null $record
 * @property-read SalesDocument|null $rectified
 * @property-read HoldedInvoice|null $rectifiedHolded
 * @property-read Collection<int, SalesDocument> $rectifications
 * @property-read SalesDocument|null $cancelledBy
 * @property-read User|null $issuer
 * @property-read User|null $creator
 * @property-read User|null $voider
 */
#[Fillable([
    'uuid', 'type', 'is_test', 'series_id', 'issue_date', 'operation_date', 'due_date',
    'client_id', 'client_name', 'language',
    'subtotal', 'discount_total', 'tax_total', 'withholding_total', 'total',
    'withholding_rate_id', 'withholding_rate',
    'body', 'internal_note', 'customer_reference', 'payment_method_id',
    'rectified_document_id', 'rectified_holded_invoice_id', 'rectification_kind', 'rectification_reason', 'rectification_code',
    'source_document_id', 'source_holded_invoice_id', 'created_by', 'updated_by',
])]
class SalesDocument extends Model
{
    use MarksNoProjectNeeded;

    /**
     * Siempre se crea como borrador (InvoicingGuards).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_test' => false,
        'language' => 'es',
        'paid_total' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SalesDocumentType::class,
            'status' => SalesDocumentStatus::class,
            'is_test' => 'boolean',
            'issue_date' => 'immutable_date',
            'operation_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'client_snapshot' => 'array',
            'issuer_snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'withholding_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'withholding_rate' => 'decimal:2',
            'rectification_kind' => RectificationKind::class,
            'voided_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'pdf_generated_at' => 'immutable_datetime',
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
     * @return BelongsTo<NumberingSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(NumberingSeries::class, 'series_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<SalesDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SalesDocumentTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(SalesDocumentTax::class)->orderByDesc('rate')->orderBy('operation_type')->orderBy('id');
    }

    /**
     * @return HasMany<SalesDocumentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(SalesDocumentLink::class)->orderBy('id');
    }

    /**
     * @return HasMany<InvoiceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(InvoiceRecord::class)->orderBy('seq');
    }

    /**
     * El registro de alta.
     *
     * @return HasOne<InvoiceRecord, $this>
     */
    public function record(): HasOne
    {
        return $this->hasOne(InvoiceRecord::class, 'id', 'invoice_record_id');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function rectified(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectified_document_id');
    }

    /**
     * @return BelongsTo<HoldedInvoice, $this>
     */
    public function rectifiedHolded(): BelongsTo
    {
        return $this->belongsTo(HoldedInvoice::class, 'rectified_holded_invoice_id');
    }

    /**
     * @return HasMany<SalesDocument, $this>
     */
    public function rectifications(): HasMany
    {
        return $this->hasMany(self::class, 'rectified_document_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cancelled_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isDraft(): bool
    {
        return $this->status === SalesDocumentStatus::Draft;
    }

    public function isCreditNote(): bool
    {
        return $this->type === SalesDocumentType::CreditNote;
    }

    /** Ficha: /facturacion/documentos/{id}. */
    public function url(): string
    {
        return '/facturacion/documentos/'.$this->id;
    }

    /** Nombre del PDF al descargarlo: «F270001.pdf» (o «borrador-12.pdf»). */
    public function pdfFilename(string $extension = 'pdf'): string
    {
        $name = $this->full_number ?? 'borrador-'.$this->id;

        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-').'.'.$extension;
    }
}
