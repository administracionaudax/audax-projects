<?php

namespace App\Domain\Billing;

use App\Enums\InvoiceLinkMethod;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use Illuminate\Contracts\Session\Session;

/**
 * «Deshacer» de la bandeja «Por revisar» (I5, D-414): la última acción de cada persona (casar,
 * confirmar o descartar contactos y enlazar facturas, una a una o en bloque) se guarda en su sesión
 * durante 30 minutos con lo necesario para volver atrás:
 * - de los contactos, cómo estaban (HoldedContactResolver::snapshot),
 * - de las facturas, los enlaces que se crearon (solo se quitan si siguen siendo manuales) y las
 *   que se marcaron «No necesita proyecto» (D-431; se les quita la marca).
 * Una acción nueva sustituye a la anterior; deshacer la borra.
 *
 * @phpstan-import-type ContactSnapshot from HoldedContactResolver
 *
 * @phpstan-type UndoAction array{message: string, count: int, at: int, contacts: list<ContactSnapshot>, links: list<int>, marks?: list<int>}
 */
final class ReviewUndo
{
    public const string KEY = 'billing.review.undo';

    public const int TTL_SECONDS = 1800;

    public function __construct(private readonly Session $session) {}

    /**
     * @param  list<ContactSnapshot>  $contacts
     * @param  list<int>  $links
     * @param  list<int>  $marks  facturas marcadas «No necesita proyecto»
     */
    public function remember(string $message, array $contacts = [], array $links = [], array $marks = []): void
    {
        if ($contacts === [] && $links === [] && $marks === []) {
            return;
        }

        $this->session->put(self::KEY, [
            'message' => $message,
            'count' => count($contacts) + count($links) + count($marks),
            'at' => now()->getTimestamp(),
            'contacts' => $contacts,
            'links' => $links,
            'marks' => $marks,
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

        $marks = $action['marks'] ?? [];
        $cleared = $marks === [] ? 0 : HoldedInvoice::query()
            ->whereIn('id', $marks)
            ->whereNotNull('no_project_needed_at')
            ->update(['no_project_needed_at' => null, 'no_project_needed_by' => null, 'no_project_note' => null]);

        BillingNav::forget();

        return count($action['contacts']) + (int) $removed + $cleared;
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
