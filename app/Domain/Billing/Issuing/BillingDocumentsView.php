<?php

namespace App\Domain\Billing\Issuing;

use Illuminate\Support\Facades\DB;

/**
 * Las vistas SQL que unen las facturas de Holded y las propias (PLAN-EMISION §4.6; D-427), con las
 * mismas columnas que `holded_invoices`, sus líneas y sus enlaces. Las leen el listado de facturas,
 * Ventas, el Resumen, «Vendido frente a real», Por facturar y las fichas, así cada informe cuenta
 * una sola vez cada factura, venga de donde venga.
 *
 * - Las propias llevan el id en negativo (`-sales_documents.id`): los ids no chocan con los de
 *   Holded y quien enlaza a la ficha sabe a cuál ir (BillingDocument::urlFor, `invoiceUrl` en TS).
 *   Igual sus líneas y sus enlaces.
 * - `billing_documents_all` trae también la serie de pruebas (PRU), que solo ve el listado en su
 *   pestaña «Pruebas»; `billing_documents` no la trae nunca: ningún informe la cuenta (V-17). Las
 *   líneas y los enlaces de las de prueba tampoco están en sus vistas.
 * - Estado de cobro de una propia (E1 aún no registra cobros, E2): borrador, anulada (también la
 *   anulada por error), rectificativa sin nada pendiente, cobrada, vencida (con el vencimiento antes
 *   de hoy), cobrada en parte o pendiente, con las mismas claves que las de Holded (D-386).
 *
 * Una migración que cambie `holded_invoices`, sus líneas o sus enlaces, o las tablas de ventas, tiene
 * que quitar las vistas antes (drop) y volver a crearlas después (create): PostgreSQL no deja cambiar
 * una columna que usa una vista y SQLite rehace la tabla al cambiarla.
 */
final class BillingDocumentsView
{
    public static function create(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW billing_documents_all AS
            SELECT
                hi.id AS id,
                'holded' AS source,
                hi.id AS source_id,
                hi.kind AS kind,
                hi.number AS number,
                hi.number_normalized AS number_normalized,
                hi.holded_contact_id AS holded_contact_id,
                hi.contact_name AS contact_name,
                hi.client_id AS client_id,
                hi.issued_on AS issued_on,
                hi.due_on AS due_on,
                hi.currency AS currency,
                hi.subtotal AS subtotal,
                hi.tax_total AS tax_total,
                hi.total AS total,
                hi.paid_total AS paid_total,
                hi.pending_total AS pending_total,
                hi.holded_status AS holded_status,
                hi.collection_status AS collection_status,
                hi.is_draft AS is_draft,
                hi.tags AS tags,
                hi.rectified_invoice_id AS rectified_invoice_id,
                hi.notes AS notes,
                hi.pdf_path AS pdf_path,
                hi.synced_at AS synced_at,
                hi.no_project_needed_at AS no_project_needed_at,
                FALSE AS is_test,
                hi.created_at AS created_at,
                hi.updated_at AS updated_at
            FROM holded_invoices hi
            UNION ALL
            SELECT
                0 - sd.id,
                'audax',
                sd.id,
                sd.type,
                sd.full_number,
                sd.full_number,
                NULL,
                sd.client_name,
                sd.client_id,
                sd.issue_date,
                sd.due_date,
                'EUR',
                sd.subtotal,
                sd.tax_total,
                sd.total,
                sd.paid_total,
                CASE WHEN sd.status = 'issued' AND sd.type = 'invoice' AND sd.total - sd.paid_total > 0 THEN sd.total - sd.paid_total ELSE 0 END,
                sd.status,
                CASE
                    WHEN sd.status = 'draft' THEN 'draft'
                    WHEN sd.status IN ('cancelled', 'voided') THEN 'cancelled'
                    WHEN sd.type = 'credit_note' THEN 'paid'
                    WHEN sd.total - sd.paid_total <= 0 THEN 'paid'
                    WHEN sd.due_date IS NOT NULL AND sd.due_date < CURRENT_DATE THEN 'overdue'
                    WHEN sd.paid_total > 0 THEN 'partial'
                    ELSE 'unpaid'
                END,
                (sd.status = 'draft'),
                NULL,
                CASE WHEN sd.rectified_document_id IS NOT NULL THEN 0 - sd.rectified_document_id ELSE sd.rectified_holded_invoice_id END,
                sd.body,
                sd.pdf_path,
                NULL,
                sd.no_project_needed_at,
                sd.is_test,
                sd.created_at,
                sd.updated_at
            FROM sales_documents sd
            SQL);

        DB::statement('CREATE VIEW billing_documents AS SELECT * FROM billing_documents_all WHERE is_test = FALSE');

        DB::statement(<<<'SQL'
            CREATE VIEW billing_document_lines AS
            SELECT
                l.id AS id,
                l.holded_invoice_id AS document_id,
                l.position AS position,
                l.name AS name,
                l.service_code AS service_code,
                l.description AS description,
                l.units AS units,
                l.unit_price AS unit_price,
                l.discount_pct AS discount_pct,
                l.subtotal AS subtotal,
                l.tax_rate AS tax_rate,
                l.holded_project_id AS holded_project_id
            FROM holded_invoice_lines l
            UNION ALL
            SELECT
                0 - sl.id,
                0 - sl.sales_document_id,
                sl.position,
                COALESCE(sl.name, sl.description),
                sl.service_code,
                sl.description,
                sl.quantity,
                sl.unit_price,
                sl.discount_pct,
                sl.line_base,
                sl.tax_rate,
                NULL
            FROM sales_document_lines sl
            JOIN sales_documents sd ON sd.id = sl.sales_document_id
            WHERE sl.kind = 'item' AND sd.is_test = FALSE
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW billing_document_links AS
            SELECT
                l.id AS id,
                l.holded_invoice_id AS document_id,
                l.project_id AS project_id,
                l.hour_bank_id AS hour_bank_id,
                l.method AS method,
                l.created_by AS created_by,
                l.created_at AS created_at,
                l.updated_at AS updated_at
            FROM holded_invoice_links l
            UNION ALL
            SELECT
                0 - sl.id,
                0 - sl.sales_document_id,
                sl.project_id,
                sl.hour_bank_id,
                sl.method,
                sl.created_by,
                sl.created_at,
                sl.updated_at
            FROM sales_document_links sl
            JOIN sales_documents sd ON sd.id = sl.sales_document_id
            WHERE sd.is_test = FALSE
            SQL);
    }

    public static function drop(): void
    {
        DB::statement('DROP VIEW IF EXISTS billing_document_links');
        DB::statement('DROP VIEW IF EXISTS billing_document_lines');
        DB::statement('DROP VIEW IF EXISTS billing_documents');
        DB::statement('DROP VIEW IF EXISTS billing_documents_all');
    }
}
