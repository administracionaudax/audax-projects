<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\TaxRegime;
use App\Http\Controllers\Billing\BillingSettingsController;
use App\Models\Client;

/**
 * Los datos fiscales del emisor y del destinatario que lleva una factura (RD 1619/2012, art. 6.1.c,
 * d y e; L-05 y L-17; D-421): la copia que se congela al emitir (`issuer_snapshot` y
 * `client_snapshot`, T-SNAP) y lo que falta para poder emitir. Cambiar la ficha fiscal del cliente o
 * los datos del emisor después no cambia una factura emitida ni su PDF.
 *
 * @phpstan-type ClientSnapshot array{name: string, legal_name: string, tax_id: string|null, eu_vat_number: string|null, address: string|null, postal_code: string|null, city: string|null, province: string|null, country_code: string, tax_regime: string, language: string}
 * @phpstan-type IssuerSnapshot array<string, string|null>
 */
final class FiscalParties
{
    /** Datos del emisor (D-383 ampliado): los de Ajustes con la forma jurídica, el nombre comercial y la web. */
    public const array ISSUER_REQUIRED = ['legal_name', 'tax_id', 'address', 'postal_code', 'city'];

    /**
     * @return ClientSnapshot
     */
    public static function client(Client $client): array
    {
        $client->loadMissing('billingProfile');
        $profile = $client->billingProfile;

        return [
            'name' => $client->name,
            'legal_name' => self::clean($profile?->legal_name) ?? $client->name,
            'tax_id' => self::clean($client->tax_id),
            'eu_vat_number' => self::clean($profile?->eu_vat_number),
            'address' => self::clean($profile?->address),
            'postal_code' => self::clean($profile?->postal_code),
            'city' => self::clean($profile?->city),
            'province' => self::clean($profile?->province),
            'country_code' => $profile->country_code ?? 'ES',
            'tax_regime' => ($profile->tax_regime ?? TaxRegime::General)->value,
            'language' => $profile->language ?? 'es',
        ];
    }

    /**
     * Lo que le falta al cliente para emitirle una factura completa: razón social (o nombre), NIF (o
     * NIF-IVA de la UE), dirección, código postal y población; a un empresario de la UE, su NIF-IVA.
     *
     * @return list<string> campos que faltan (claves de invoicing.fields.*)
     */
    public static function clientMissing(Client $client): array
    {
        $data = self::client($client);
        $missing = [];

        if ($data['tax_id'] === null && $data['eu_vat_number'] === null) {
            $missing[] = 'tax_id';
        }
        foreach (['address', 'postal_code', 'city'] as $field) {
            if ($data[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($data['tax_regime'] === TaxRegime::IntraEu->value && $data['eu_vat_number'] === null && ! in_array('tax_id', $missing, true)) {
            $missing[] = 'eu_vat_number';
        }

        return array_values($missing);
    }

    /**
     * @return IssuerSnapshot
     */
    public static function issuer(): array
    {
        return BillingSettingsController::issuer();
    }

    /**
     * @return list<string>
     */
    public static function issuerMissing(): array
    {
        $issuer = self::issuer();

        return array_values(array_filter(self::ISSUER_REQUIRED, fn (string $field): bool => self::clean($issuer[$field] ?? null) === null));
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
