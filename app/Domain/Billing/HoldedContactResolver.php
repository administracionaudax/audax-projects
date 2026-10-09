<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolver a mano un contacto de Holded (D-387, D-248 y D-413): elegir su cliente (`assign`), dar
 * por bueno el de un nombre parecido (`confirm`), descartarlo (`ignore`, no es un cliente de la
 * agencia) o volver a casarlo solo (`auto`). Sus facturas pasan al cliente elegido (o se quedan
 * sin cliente). Nunca crea un cliente (D-387).
 *
 * Devuelve cómo estaba el contacto antes, para «Deshacer» (ReviewUndo, I5).
 *
 * @phpstan-type ContactSnapshot array{id: int, client_id: int|null, match_method: string|null, ignored_at: string|null, resolved_by: int|null}
 */
final class HoldedContactResolver
{
    public const array ACTIONS = ['assign', 'ignore', 'auto', 'confirm'];

    public function __construct(private readonly HoldedContactMatcher $matcher) {}

    /**
     * @return ContactSnapshot
     */
    public function apply(HoldedContact $contact, string $action, ?int $clientId, User $user): array
    {
        $before = self::snapshot($contact);

        DB::transaction(function () use ($contact, $action, $clientId, $user): void {
            match ($action) {
                'assign' => $contact->forceFill(['client_id' => $clientId, 'match_method' => HoldedContact::MATCH_MANUAL, 'ignored_at' => null, 'resolved_by' => $user->id]),
                // Da por bueno el cliente de un nombre parecido (D-248): pasa a hecho a mano.
                'confirm' => $contact->forceFill(['match_method' => $contact->client_id !== null ? HoldedContact::MATCH_MANUAL : $contact->match_method, 'resolved_by' => $user->id]),
                'ignore' => $contact->forceFill(['client_id' => null, 'match_method' => null, 'ignored_at' => now(), 'resolved_by' => $user->id]),
                default => $contact->forceFill(['client_id' => null, 'match_method' => null, 'ignored_at' => null, 'resolved_by' => null]),
            };

            if ($action === 'auto') {
                $this->matcher->match($contact);
            }
            $contact->save();

            if ($contact->client_id !== null) {
                $this->matcher->fillClient($contact);
            }

            self::moveInvoices($contact);
        });

        BillingNav::forget();

        return $before;
    }

    /**
     * Deja un contacto como estaba (Deshacer). Los datos fiscales que se rellenaron en el cliente
     * se quedan: solo se escribieron campos vacíos y siguen siendo ciertos.
     *
     * @param  ContactSnapshot  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $contact = HoldedContact::query()->find($snapshot['id']);
        if ($contact === null) {
            return;
        }

        DB::transaction(function () use ($contact, $snapshot): void {
            $contact->forceFill([
                'client_id' => $snapshot['client_id'],
                'match_method' => $snapshot['match_method'],
                'ignored_at' => $snapshot['ignored_at'],
                'resolved_by' => $snapshot['resolved_by'],
            ])->save();

            self::moveInvoices($contact);
        });

        BillingNav::forget();
    }

    /**
     * @return ContactSnapshot
     */
    public static function snapshot(HoldedContact $contact): array
    {
        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'match_method' => $contact->match_method,
            'ignored_at' => $contact->ignored_at?->toIso8601String(),
            'resolved_by' => $contact->resolved_by,
        ];
    }

    private static function moveInvoices(HoldedContact $contact): void
    {
        HoldedInvoice::query()->where('holded_contact_id', $contact->holded_id)->update(['client_id' => $contact->client_id]);
    }
}
