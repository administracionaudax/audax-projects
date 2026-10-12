<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Models\User;

/**
 * La matriz estado → acciones (PLAN-EMISION §3.2, contrato de L1; D-428): la cabecera de la ficha,
 * el menú de cada fila y el servidor leen la misma tabla. Copia en TypeScript en
 * `resources/js/components/invoicing/document-actions.ts`, con los casos compartidos en
 * `tests/fixtures/billing/document-actions.json` (Pest y Vitest).
 *
 * Papeles: `view` prepara (use-invoicing: view-billing con el módulo), `manage` además emite, anula
 * y rectifica (manage-billing) y `admin` además anula el registro de una factura que no debió existir
 * (V-03). Una acción que no está aquí no se ofrece; el servidor comprueba además las condiciones
 * concretas (por ejemplo, que no haya cobros para anular el registro).
 *
 * E2 añade cobrar, anular un cobro, enviar, recordar el cobro y los adjuntos. E3 añade el estado
 * «programada» (un borrador con fecha de emisión futura: editarlo, borrarlo o emitirlo antes es de
 * quien emite), programar y desprogramar, y «Hacer recurrente». Fuera aún: el estado en la AEAT (E7).
 */
final class DocumentActions
{
    public const array STATES = ['draft', 'scheduled', 'issued', 'paid', 'cancelled', 'voided', 'credit_note', 'holded'];

    public const array ROLES = ['view', 'manage', 'admin'];

    public const array ACTIONS = ['view', 'edit', 'delete', 'issue', 'duplicate', 'download_pdf', 'edit_non_fiscal', 'cancel', 'rectify', 'void', 'view_record', 'open_holded', 'record_payment', 'void_payment', 'send', 'send_reminder', 'attach', 'schedule', 'unschedule', 'make_recurring'];

    /** Acción → estado → papel mínimo (null: no se ofrece). */
    private const array MATRIX = [
        'view' => ['draft' => 'view', 'scheduled' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        // Una programada se emitirá sola: cambiarla o borrarla es de quien emite (y al editarla se desprograma).
        'edit' => ['draft' => 'view', 'scheduled' => 'manage'],
        'delete' => ['draft' => 'view', 'scheduled' => 'manage'],
        'issue' => ['draft' => 'manage', 'scheduled' => 'manage'],
        'duplicate' => ['draft' => 'view', 'scheduled' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'holded' => 'view'],
        'download_pdf' => ['draft' => 'view', 'scheduled' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        'edit_non_fiscal' => ['draft' => 'view', 'scheduled' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        'cancel' => ['issued' => 'manage', 'paid' => 'manage'],
        'rectify' => ['issued' => 'manage', 'paid' => 'manage'],
        'void' => ['issued' => 'admin', 'credit_note' => 'admin'],
        'view_record' => ['issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view'],
        'open_holded' => ['holded' => 'view'],
        // E2 (D-436 a D-444): cobrar (en una rectificativa, la devolución), anular un cobro, enviar
        // por email, recordar el cobro y los adjuntos. El servidor comprueba además que quede algo
        // por cobrar (record_payment, send_reminder).
        'record_payment' => ['issued' => 'manage', 'paid' => 'manage', 'credit_note' => 'manage'],
        'void_payment' => ['issued' => 'manage', 'paid' => 'manage', 'cancelled' => 'manage', 'credit_note' => 'manage'],
        'send' => ['issued' => 'manage', 'paid' => 'manage', 'credit_note' => 'manage'],
        'send_reminder' => ['issued' => 'manage', 'paid' => 'manage'],
        'attach' => ['draft' => 'view', 'scheduled' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view'],
        // E3: programar (o cambiar la fecha) y desprogramar, y «Hacer recurrente» (H-033).
        'schedule' => ['draft' => 'manage', 'scheduled' => 'manage'],
        'unschedule' => ['scheduled' => 'manage'],
        'make_recurring' => ['draft' => 'manage', 'issued' => 'manage', 'paid' => 'manage', 'holded' => 'manage'],
    ];

    /**
     * @return list<string>
     */
    public static function for(string $state, ?string $role): array
    {
        if ($role === null) {
            return [];
        }

        $rank = array_search($role, self::ROLES, true);
        $actions = [];

        foreach (self::ACTIONS as $action) {
            $needed = self::MATRIX[$action][$state] ?? null;
            if ($needed !== null && $rank !== false && $rank >= array_search($needed, self::ROLES, true)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /** El papel de una persona en la emisión (null: no la usa). */
    public static function role(?User $user): ?string
    {
        return match (true) {
            InvoicingAccess::voids($user) => 'admin',
            InvoicingAccess::manages($user) => 'manage',
            InvoicingAccess::uses($user) => 'view',
            default => null,
        };
    }

    public static function state(SalesDocument $document): string
    {
        return match (true) {
            $document->isScheduled() => 'scheduled',
            $document->status === SalesDocumentStatus::Draft => 'draft',
            $document->status === SalesDocumentStatus::Cancelled => 'cancelled',
            $document->status === SalesDocumentStatus::Voided => 'voided',
            $document->isCreditNote() => 'credit_note',
            bccomp(DocumentTotals::num((string) $document->paid_total), '0', 2) > 0 => 'paid',
            default => 'issued',
        };
    }

    /**
     * @return list<string>
     */
    public static function forDocument(SalesDocument $document, ?User $user): array
    {
        return self::for(self::state($document), self::role($user));
    }

    public static function allows(SalesDocument $document, ?User $user, string $action): bool
    {
        return in_array($action, self::forDocument($document, $user), true);
    }
}
