<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío por email de una factura propia (PLAN-EMISION §3.4, E2; D-438 a D-441): factura,
 * rectificativa o recordatorio, con los destinatarios, el asunto y el texto tal como salieron, los
 * adjuntos y la huella del PDF. Sale por la cola `mail` (SendSalesDocumentEmail): `queued` hasta que
 * el relé lo acepta (`sent`) o falla (`failed`, con el error). Las vistas son las del enlace
 * firmado de este envío («abierta por el cliente», sin píxel espía).
 *
 * @property int $id
 * @property int $sales_document_id
 * @property string $kind send | reminder
 * @property string $template invoice | credit_note | reminder
 * @property string $language
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property string $subject
 * @property string $body
 * @property list<string>|null $attachments
 * @property string|null $pdf_sha256
 * @property string|null $message_id
 * @property string $status queued | sent | failed
 * @property string|null $error
 * @property bool $test_mode
 * @property int|null $reminder_rule_id
 * @property int|null $sent_by
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $first_viewed_at
 * @property CarbonImmutable|null $last_viewed_at
 * @property int $view_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocument $document
 * @property-read User|null $sender
 * @property-read PaymentReminderRule|null $rule
 */
#[Fillable([
    'sales_document_id', 'kind', 'template', 'language', 'to', 'cc', 'bcc', 'subject', 'body', 'attachments',
    'pdf_sha256', 'test_mode', 'reminder_rule_id', 'sent_by',
])]
class SalesDocumentEmail extends Model
{
    public const string QUEUED = 'queued';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::QUEUED,
        'view_count' => 0,
        'test_mode' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'test_mode' => 'boolean',
            'sent_at' => 'immutable_datetime',
            'first_viewed_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'view_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<PaymentReminderRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PaymentReminderRule::class, 'reminder_rule_id');
    }
}
