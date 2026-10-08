<?php

namespace App\Models;

use App\Enums\BillingPaymentMethod;
use App\Enums\TaxRegime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha fiscal del cliente (Fase 12, D-381; PLAN-FASE-12 §6.2, H-001 a H-008). 1:1 con `clients`;
 * el NIF sigue en `clients.tax_id`. Solo la ven y la editan quienes tienen view-billing.
 *
 * @property int $id
 * @property int $client_id
 * @property string|null $legal_name
 * @property string|null $eu_vat_number
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $province
 * @property string $country_code
 * @property TaxRegime $tax_regime
 * @property BillingPaymentMethod|null $payment_method
 * @property int|null $payment_days
 * @property int|null $payment_day
 * @property string $language
 * @property list<string>|null $billing_emails
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 */
#[Fillable([
    'client_id',
    'legal_name',
    'eu_vat_number',
    'address',
    'postal_code',
    'city',
    'province',
    'country_code',
    'tax_regime',
    'payment_method',
    'payment_days',
    'payment_day',
    'language',
    'billing_emails',
])]
class ClientBillingProfile extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'country_code' => 'ES',
        'tax_regime' => 'general',
        'language' => 'es',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_regime' => TaxRegime::class,
            'payment_method' => BillingPaymentMethod::class,
            'payment_days' => 'integer',
            'payment_day' => 'integer',
            'billing_emails' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** ¿Están los datos mínimos para que una factura sea válida (H-001)? Razón social y dirección completa. */
    public function isComplete(): bool
    {
        foreach (['legal_name', 'address', 'postal_code', 'city'] as $field) {
            if (trim((string) $this->getAttribute($field)) === '') {
                return false;
            }
        }

        return true;
    }
}
