<?php

namespace App\Domain\Billing;

use App\Enums\InvoiceLinkMethod;
use App\Models\HoldedInvoiceLink;
use Illuminate\Contracts\Session\Session;

/**
 * «Deshacer» de la bandeja «Por revisar» (I5, D-414): la última acción de cada persona (casar,
 * confirmar o descartar contactos y enlazar facturas, una a una o en bloque) se guarda en su sesión
 * durante 30 minutos con lo necesario para volver atrás:
 * - de los contactos, cómo estaban (HoldedContactResolver::snapshot),
 * - de las facturas, los enlaces que se crearon (solo se quitan si siguen siendo manuales).
 * Una acción nueva sustituye a la anterior; deshacer la borra.
 *
 * @phpstan-import-type ContactSnapshot from HoldedContactResolver
 *
 * @phpstan-type UndoAction array{message: string, count: int, at: int, contacts: list<ContactSnapshot>, links: list<int>}
 */
final class ReviewUndo
{
    public const string KEY = 'billing.review.undo';

    public const int TTL_SECONDS = 1800;

    public function __construct(private readonly Session $session) {}

    /**
     * @param  list<ContactSnapshot>  $contacts
     * @param  list<int>  $links
     */
    public function remember(string $message, array $contacts = [], array $links = []): void
    {
        if ($contacts === [] && $links === []) {
            return;
        }

        $this->session->put(self::KEY, [
            'message' => $message,
            'count' => count($contacts) + count($links),
            'at' => now()->getTimestamp(),
            'contacts' => $contacts,
            'links' => $links,
        ]);
    }

    /**
     * La última acción que aún se puede deshacer (para la página), sin los datos internos.
     *
     * @return array{message: string, count: int}|null
     */
    public function last(): ?array
    {
        $action = $this->action();

        return $action === null ? null : ['message' => $action['message'], 'count' => $action['count']];
    }

    /** Deshace la última acción. Devuelve cuántos elementos ha vuelto atrás. */
    public function undo(HoldedContactResolver $resolver): int
    {
        $action = $this->action();
        $this->session->forget(self::KEY);

        if ($action === null) {
            return 0;
        }

        foreach ($action['contacts'] as $snapshot) {
            $resolver->restore($snapshot);
        }

        $removed = $action['links'] === [] ? 0 : HoldedInvoiceLink::query()
            ->whereIn('id', $action['links'])
            ->where('method', InvoiceLinkMethod::Manual->value)
            ->delete();

        BillingNav::forget();

        return count($action['contacts']) + (int) $removed;
    }

    /**
     * @return UndoAction|null
     */
    private function action(): ?array
    {
        $action = $this->session->get(self::KEY);

        if (! is_array($action) || ! isset($action['at'], $action['message']) || now()->getTimestamp() - (int) $action['at'] > self::TTL_SECONDS) {
            return null;
        }

        /** @var UndoAction $action */
        return $action;
    }
}
