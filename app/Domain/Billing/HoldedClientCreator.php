<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crear el cliente de Audax desde un contacto de Holded sin cliente (D-430, cambia D-387): el
 * propietario lo pidió el 09/10/2026 para los contactos que son clientes de verdad y aún no están
 * en Audax.
 * - `draft()`: los datos del diálogo, ya rellenos y editables (nombre: el comercial o, si no, la
 *   razón social sin la forma jurídica; NIF; email; y la ficha fiscal: razón social, dirección, CP,
 *   ciudad, provincia y país) y los clientes que ya podrían ser él (mismo NIF o nombre muy parecido,
 *   HoldedContactMatcher::similarClients), para casarlo con uno de ellos en vez de crear.
 * - `create()`: el cliente activo con su ficha fiscal, el contacto casado «a mano» (sus facturas
 *   pasan al cliente, HoldedContactResolver) y una entrada en la auditoría con el origen.
 * La sincronización no lo deshace: lo hecho a mano no lo toca (D-387).
 *
 * @phpstan-type ClientDraft array{name: string, tax_id: string|null, email: string|null, legal_name: string|null, address: string|null, postal_code: string|null, city: string|null, province: string|null, country_code: string}
 * @phpstan-type Candidate array{id: int, name: string, tax_id: string|null, is_active: bool, reason: 'nif'|'nombre'|'parecido'}
 */
final class HoldedClientCreator
{
    /** Evento de la auditoría del cliente creado desde Holded. */
    public const string AUDIT_EVENT = 'created_from_holded';

    /** Formas jurídicas que se quitan del final de la razón social (con o sin puntos). */
    private const string LEGAL_FORM = '/[\s,]+(?:s\.?\s?l\.?\s?u\.?|s\.?\s?l\.?\s?l\.?|s\.?\s?l\.?|s\.?\s?a\.?\s?u\.?|s\.?\s?a\.?|s\.?\s?coop\.?(?:\s?v\.?)?|soc(?:iedad)?\.?\s+limitada|soc(?:iedad)?\.?\s+an[oó]nima|c\.?\s?b\.?|s\.?\s?c\.?|s\.?\s?l\.?\s?p\.?)\s*$/iu';

    public function __construct(
        private readonly HoldedContactMatcher $matcher,
        private readonly HoldedContactResolver $resolver,
    ) {}

    /**
     * @return array{draft: ClientDraft, candidates: list<Candidate>}
     */
    public function draft(HoldedContact $contact): array
    {
        $address = $contact->address ?? [];
        $string = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $draft = [
            'name' => self::suggestedName($contact),
            'tax_id' => $contact->tax_id_normalized ?? $string($contact->tax_id),
            'email' => $string($contact->email),
            'legal_name' => Str::limit(trim($contact->name), 200, ''),
            'address' => $string($address['address'] ?? null),
            'postal_code' => $string($address['postal_code'] ?? null),
            'city' => $string($address['city'] ?? null),
            'province' => $string($address['province'] ?? null),
            'country_code' => strtoupper($contact->country_code ?? $string($address['country_code'] ?? null) ?? 'ES'),
        ];

        return ['draft' => $draft, 'candidates' => $this->candidates($contact, $draft['name'], $draft['tax_id'])];
    }

    /**
     * Clientes que ya podrían ser el del contacto (o el que se va a crear con $name y $taxId).
     *
     * @return list<Candidate>
     */
    public function candidates(HoldedContact $contact, ?string $name, ?string $taxId): array
    {
        $found = $this->matcher->similarClients([$contact->name, $contact->trade_name, $name], $taxId ?? $contact->tax_id_normalized);
        if ($found === []) {
            return [];
        }

        $order = ['nif' => 0, 'nombre' => 1, 'parecido' => 2];
        $clients = Client::query()->whereIn('id', array_keys($found))->orderBy('name')->orderBy('id')->get(['id', 'name', 'tax_id', 'is_active']);

        return array_values($clients
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'tax_id' => $client->tax_id, 'is_active' => $client->is_active, 'reason' => $found[$client->id]])
            ->sortBy(fn (array $candidate): int => $order[$candidate['reason']])
            ->take(10)
            ->values()
            ->all());
    }

    /**
     * @param  ClientDraft  $data
     */
    public function create(HoldedContact $contact, array $data, User $user): Client
    {
        return DB::transaction(function () use ($contact, $data, $user): Client {
            $client = Client::query()->create([
                'name' => $data['name'],
                'tax_id' => $data['tax_id'],
                'contact_email' => $data['email'],
                'is_active' => true,
            ]);

            // Casado a mano: sus facturas pasan al cliente (y la sincronización ya no lo toca).
            $this->resolver->apply($contact, 'assign', $client->id, $user);

            // Lo del diálogo manda sobre lo que el casado rellena desde Holded (también lo vaciado).
            $client->tax_id = $data['tax_id'];
            $client->save();
            ClientBillingProfile::query()->updateOrCreate(['client_id' => $client->id], [
                'legal_name' => $data['legal_name'],
                'address' => $data['address'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'province' => $data['province'],
                'country_code' => $data['country_code'],
            ]);

            activity($client->getTable())
                ->performedOn($client)
                ->causedBy($user)
                ->event(self::AUDIT_EVENT)
                ->withProperties([
                    'origin' => 'holded',
                    'holded_contact_id' => $contact->id,
                    'holded_id' => $contact->holded_id,
                    'contact_name' => $contact->name,
                    'contact_tax_id' => $contact->tax_id,
                ])
                ->log('Cliente creado desde un contacto de Holded');

            return $client;
        });
    }

    /** El nombre comercial o, si no hay, la razón social sin la forma jurídica («PINTURAS MONTÓ, S.A.U» → «PINTURAS MONTÓ»). */
    public static function suggestedName(HoldedContact $contact): string
    {
        $trade = trim((string) $contact->trade_name);
        if ($trade !== '') {
            return Str::limit($trade, 255, '');
        }

        $name = trim((string) preg_replace(self::LEGAL_FORM, '', trim($contact->name)));

        return Str::limit(rtrim($name === '' ? trim($contact->name) : $name, ' ,.-'), 255, '');
    }
}
