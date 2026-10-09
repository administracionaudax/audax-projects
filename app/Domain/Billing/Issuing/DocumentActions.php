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
 * Fuera de E1: programar, enviar, enlace del cliente, cobros, recordatorios, hacer recurrente y
 * el estado en la AEAT (E2, E3 y E7).
 */
final class DocumentActions
{
    public const array STATES = ['draft', 'issued', 'paid', 'cancelled', 'voided', 'credit_note', 'holded'];

    public const array ROLES = ['view', 'manage', 'admin'];

    public const array ACTIONS = ['view', 'edit', 'delete', 'issue', 'duplicate', 'download_pdf', 'edit_non_fiscal', 'cancel', 'rectify', 'void', 'view_record', 'open_holded'];

    /** Acción → estado → papel mínimo (null: no se ofrece). */
    private const array MATRIX = [
        'view' => ['draft' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        'edit' => ['draft' => 'view'],
        'delete' => ['draft' => 'view'],
        'issue' => ['draft' => 'manage'],
        'duplicate' => ['draft' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'holded' => 'view'],
        'download_pdf' => ['draft' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        'edit_non_fiscal' => ['draft' => 'view', 'issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view', 'holded' => 'view'],
        'cancel' => ['issued' => 'manage', 'paid' => 'manage'],
        'rectify' => ['issued' => 'manage', 'paid' => 'manage'],
        'void' => ['issued' => 'admin', 'credit_note' => 'admin'],
        'view_record' => ['issued' => 'view', 'paid' => 'view', 'cancelled' => 'view', 'voided' => 'view', 'credit_note' => 'view'],
        'open_holded' => ['holded' => 'view'],
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
