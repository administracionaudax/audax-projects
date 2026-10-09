/**
 * La matriz estado → acciones de la emisión propia (PLAN-EMISION §3.2; D-428): la misma tabla que
 * App\Domain\Billing\Issuing\DocumentActions, con los casos compartidos en
 * `tests/fixtures/billing/document-actions.json` (Vitest y Pest). La ficha recibe las acciones del
 * servidor; el menú de cada fila del listado las calcula aquí con el estado y las habilidades.
 */

export type DocumentState =
    | 'draft'
    | 'issued'
    | 'paid'
    | 'cancelled'
    | 'voided'
    | 'credit_note'
    | 'holded';

export type DocumentRole = 'view' | 'manage' | 'admin';

export type DocumentAction =
    | 'view'
    | 'edit'
    | 'delete'
    | 'issue'
    | 'duplicate'
    | 'download_pdf'
    | 'edit_non_fiscal'
    | 'cancel'
    | 'rectify'
    | 'void'
    | 'view_record'
    | 'open_holded';

const ROLES: DocumentRole[] = ['view', 'manage', 'admin'];

const ACTIONS: DocumentAction[] = [
    'view',
    'edit',
    'delete',
    'issue',
    'duplicate',
    'download_pdf',
    'edit_non_fiscal',
    'cancel',
    'rectify',
    'void',
    'view_record',
    'open_holded',
];

const ALL_VIEW: Partial<Record<DocumentState, DocumentRole>> = {
    draft: 'view',
    issued: 'view',
    paid: 'view',
    cancelled: 'view',
    voided: 'view',
    credit_note: 'view',
    holded: 'view',
};

const MATRIX: Record<
    DocumentAction,
    Partial<Record<DocumentState, DocumentRole>>
> = {
    view: ALL_VIEW,
    edit: { draft: 'view' },
    delete: { draft: 'view' },
    issue: { draft: 'manage' },
    duplicate: {
        draft: 'view',
        issued: 'view',
        paid: 'view',
        cancelled: 'view',
        voided: 'view',
        holded: 'view',
    },
    download_pdf: ALL_VIEW,
    edit_non_fiscal: ALL_VIEW,
    cancel: { issued: 'manage', paid: 'manage' },
    rectify: { issued: 'manage', paid: 'manage' },
    void: { issued: 'admin', credit_note: 'admin' },
    view_record: {
        issued: 'view',
        paid: 'view',
        cancelled: 'view',
        voided: 'view',
        credit_note: 'view',
    },
    open_holded: { holded: 'view' },
};

export function documentActions(
    state: DocumentState,
    role: DocumentRole | null,
): DocumentAction[] {
    if (role === null) {
        return [];
    }

    const rank = ROLES.indexOf(role);

    return ACTIONS.filter((action) => {
        const needed = MATRIX[action][state];

        return needed !== undefined && rank >= ROLES.indexOf(needed);
    });
}

/** El papel con las habilidades compartidas (auth.can). */
export function documentRole(can: {
    useInvoicing?: boolean;
    manageBilling?: boolean;
    voidInvoices?: boolean;
}): DocumentRole | null {
    if (can.voidInvoices) {
        return 'admin';
    }

    if (can.manageBilling) {
        return 'manage';
    }

    return can.useInvoicing ? 'view' : null;
}
