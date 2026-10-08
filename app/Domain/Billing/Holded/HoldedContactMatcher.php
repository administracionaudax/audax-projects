<?php

namespace App\Domain\Billing\Holded;

use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use Illuminate\Support\Str;

/**
 * Casa los contactos de Holded con los clientes de Audax (Fase 12, F1; D-387):
 * 1. por NIF (sin espacios, guiones ni el prefijo «ES» del NIF-IVA),
 * 2. si no, por el nombre o el nombre comercial normalizados (sin tildes, signos ni la forma
 *    jurídica: «Hoteles Mirador, S.A.» = «Hoteles Mirador»), solo si casa con UN cliente.
 * Nunca crea clientes ni cambia un enlace hecho a mano o un contacto descartado. Al casar, rellena
 * los datos fiscales que el cliente aún no tiene (NIF, razón social, dirección y país); lo que ya
 * está escrito en Audax no se toca.
 */
final class HoldedContactMatcher
{
    /** Formas jurídicas que se quitan del nombre al compararlo. */
    private const array LEGAL_FORMS = ['s l u', 's l l', 's l', 's a u', 's a', 'slu', 'sll', 'sl', 'sau', 'sa', 's coop', 'scoop', 'sociedad limitada', 'sociedad anonima', 'cb', 's c', 'sc'];

    /** @var array<string, list<int>>|null NIF normalizado → clientes */
    private ?array $byTaxId = null;

    /** @var array<string, list<int>>|null nombre normalizado → clientes */
    private ?array $byName = null;

    public function match(HoldedContact $contact): void
    {
        if ($contact->match_method === HoldedContact::MATCH_MANUAL || $contact->ignored_at !== null) {
            return;
        }

        $this->load();
        [$clientId, $method] = $this->find($contact);

        $contact->client_id = $clientId;
        $contact->match_method = $method;
    }

    /** Rellena lo que le falta al cliente con los datos de Holded (solo campos vacíos). */
    public function fillClient(HoldedContact $contact): void
    {
        if ($contact->client_id === null) {
            return;
        }

        $client = Client::query()->withTrashed()->find($contact->client_id);
        if ($client === null) {
            return;
        }

        if (trim((string) $client->tax_id) === '' && $contact->tax_id !== null) {
            $client->tax_id = $contact->tax_id_normalized ?? $contact->tax_id;
            $client->save();
        }

        $profile = ClientBillingProfile::query()->firstOrNew(['client_id' => $client->id]);
        $address = $contact->address ?? [];
        $values = [
            'legal_name' => $contact->name,
            'address' => $address['address'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['province'] ?? null,
        ];

        foreach ($values as $field => $value) {
            if (trim((string) $profile->getAttribute($field)) === '' && is_string($value) && trim($value) !== '') {
                $profile->setAttribute($field, Str::limit(trim($value), 190, ''));
            }
        }

        if (! $profile->exists && $contact->country_code !== null) {
            $profile->country_code = strtoupper($contact->country_code);
        }

        if ($profile->isDirty()) {
            $profile->save();
        }
    }

    /** Olvida los clientes leídos (si cambian durante la sincronización). */
    public function reset(): void
    {
        $this->byTaxId = null;
        $this->byName = null;
    }

    public static function normalizeName(?string $name): string
    {
        $value = Str::lower(Str::ascii((string) $name));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = ' '.trim($value).' ';

        foreach (self::LEGAL_FORMS as $form) {
            if (str_ends_with($value, ' '.$form.' ')) {
                $value = substr($value, 0, -strlen($form) - 1);
                break;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function find(HoldedContact $contact): array
    {
        $taxId = $contact->tax_id_normalized;
        if ($taxId !== null && count($this->byTaxId[$taxId] ?? []) === 1) {
            return [$this->byTaxId[$taxId][0], HoldedContact::MATCH_TAX_ID];
        }

        foreach ([$contact->name, $contact->trade_name] as $name) {
            $key = self::normalizeName($name);
            if ($key !== '' && count($this->byName[$key] ?? []) === 1) {
                return [$this->byName[$key][0], HoldedContact::MATCH_NAME];
            }
        }

        return [null, null];
    }

    private function load(): void
    {
        if ($this->byTaxId !== null && $this->byName !== null) {
            return;
        }

        $this->byTaxId = [];
        $this->byName = [];

        foreach (Client::query()->orderBy('id')->get(['id', 'name', 'tax_id']) as $client) {
            $taxId = HoldedPayload::normalizeTaxId($client->tax_id);
            if ($taxId !== null) {
                $this->byTaxId[$taxId][] = $client->id;
            }

            $name = self::normalizeName($client->name);
            if ($name !== '') {
                $this->byName[$name][] = $client->id;
            }
        }
    }
}
