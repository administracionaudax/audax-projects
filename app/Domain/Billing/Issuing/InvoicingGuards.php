<?php

namespace App\Domain\Billing\Issuing;

use Illuminate\Support\Facades\DB;

/**
 * Las garantías de la base de datos de la emisión propia (PLAN-EMISION §4.3; D-420 y D-421), como
 * las del registro de jornada (`create_people_r2_tables`): ni un error de la app ni un cambio a mano
 * pueden alterar lo emitido.
 *
 * - `invoice_records`: solo alta (UPDATE, DELETE y TRUNCATE fallan). Al insertar se comprueba la
 *   cadena (el número de orden sigue al último de su instalación, la huella anterior es la del último
 *   y el primero lleva `is_first`) y, en PostgreSQL, se recalcula la huella SHA-256 con la misma
 *   cadena que la especificación de la AEAT: si no coincide, se rechaza.
 * - `sales_documents`: se crean siempre como borrador; una emitida no se borra y no cambia nada
 *   fiscal (serie, número, fechas, partes, copias, importes, textos del PDF y el PDF una vez puesto).
 *   Solo pasa de emitida a anulada (con su rectificativa) o a anulada por error (con su motivo).
 *   Coherencia: emitida ⇔ número, serie, año, copias y registro; rectificativa ⇔ factura rectificada
 *   y motivo.
 * - `sales_document_lines` y `sales_document_taxes`: no se tocan si su factura no es un borrador.
 * - `numbering_counters`: solo sube y no se borra.
 *
 * SQLite (tests en el Mac) tiene los mismos *triggers* salvo el recálculo de la huella (no tiene
 * SHA-256): eso se prueba en PostgreSQL (servidor y CI) y la app la recalcula en
 * `app:billing-verify-chain`.
 */
final class InvoicingGuards
{
    /** Campos fiscales de una factura: no cambian una vez emitida. */
    public const array FROZEN = [
        'uuid', 'type', 'is_test', 'series_id', 'year', 'number', 'full_number', 'issue_date', 'operation_date', 'due_date',
        'client_id', 'client_name', 'client_snapshot', 'issuer_snapshot', 'language',
        'subtotal', 'discount_total', 'tax_total', 'withholding_total', 'total', 'withholding_rate_id', 'withholding_rate',
        'body', 'customer_reference', 'payment_method_id', 'payment_text',
        'rectified_document_id', 'rectified_holded_invoice_id', 'rectification_kind', 'rectification_reason', 'rectification_code',
        'issued_at', 'issued_by', 'invoice_record_id', 'created_at',
    ];

    /** Campos que, una vez puestos, no cambian (el PDF archivado y las anulaciones). */
    public const array ONCE = ['pdf_path', 'pdf_sha256', 'cancelled_by_id', 'voided_at', 'voided_by', 'void_reason'];

    public static function install(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => self::installPostgres(),
            'sqlite' => self::installSqlite(),
            default => null,
        };
    }

    public static function drop(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS invoice_records_chain ON invoice_records;
                DROP TRIGGER IF EXISTS invoice_records_append_only ON invoice_records;
                DROP TRIGGER IF EXISTS invoice_records_no_truncate ON invoice_records;
                DROP TRIGGER IF EXISTS sales_documents_guard_row ON sales_documents;
                DROP TRIGGER IF EXISTS sales_documents_no_truncate ON sales_documents;
                DROP TRIGGER IF EXISTS sales_document_lines_guard ON sales_document_lines;
                DROP TRIGGER IF EXISTS sales_document_taxes_guard ON sales_document_taxes;
                DROP TRIGGER IF EXISTS numbering_counters_guard ON numbering_counters;
                DROP FUNCTION IF EXISTS invoice_records_chain();
                DROP FUNCTION IF EXISTS invoice_records_append_only();
                DROP FUNCTION IF EXISTS sales_documents_guard();
                DROP FUNCTION IF EXISTS sales_document_children_guard();
                DROP FUNCTION IF EXISTS numbering_counters_guard();
                SQL);
        }
    }

    /** La cadena de la huella del registro nuevo, en SQL (la misma que RecordHasher). */
    private const string PG_HASH_INPUT = "CASE WHEN NEW.kind = 'alta' THEN"
        ." 'IDEmisorFactura=' || NEW.issuer_tax_id || '&NumSerieFactura=' || NEW.invoice_number"
        ." || '&FechaExpedicionFactura=' || NEW.issue_date_text || '&TipoFactura=' || NEW.invoice_type"
        ." || '&CuotaTotal=' || NEW.tax_total_text || '&ImporteTotal=' || NEW.total_text"
        ." || '&Huella=' || NEW.previous_hash || '&FechaHoraHusoGenRegistro=' || NEW.generated_at_text"
        ." WHEN NEW.kind = 'anulacion' THEN"
        ." 'IDEmisorFacturaAnulada=' || NEW.issuer_tax_id || '&NumSerieFacturaAnulada=' || NEW.invoice_number"
        ." || '&FechaExpedicionFacturaAnulada=' || NEW.issue_date_text"
        ." || '&Huella=' || NEW.previous_hash || '&FechaHoraHusoGenRegistro=' || NEW.generated_at_text"
        .' END';

    private static function installPostgres(): void
    {
        $frozen = implode("\n                        OR ", array_map(
            fn (string $column): string => in_array($column, ['client_snapshot', 'issuer_snapshot'], true)
                ? "NEW.{$column}::text IS DISTINCT FROM OLD.{$column}::text"
                : "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            self::FROZEN,
        ));
        $once = implode("\n                        OR ", array_map(
            fn (string $column): string => "(OLD.{$column} IS NOT NULL AND NEW.{$column} IS DISTINCT FROM OLD.{$column})",
            self::ONCE,
        ));

        DB::unprepared(sprintf(<<<'SQL'
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_status_check
                CHECK (status IN ('draft', 'issued', 'cancelled', 'voided'));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_type_check
                CHECK (type IN ('invoice', 'credit_note'));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_issued_check
                CHECK (status = 'draft' OR (series_id IS NOT NULL AND year IS NOT NULL AND number IS NOT NULL AND full_number IS NOT NULL
                    AND issued_at IS NOT NULL AND invoice_record_id IS NOT NULL AND client_snapshot IS NOT NULL AND issuer_snapshot IS NOT NULL));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_draft_check
                CHECK (status <> 'draft' OR (number IS NULL AND full_number IS NULL AND invoice_record_id IS NULL));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_credit_check
                CHECK (type <> 'credit_note' OR ((rectified_document_id IS NOT NULL OR rectified_holded_invoice_id IS NOT NULL)
                    AND rectification_kind IS NOT NULL AND rectification_reason IS NOT NULL));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_cancelled_check
                CHECK (status <> 'cancelled' OR cancelled_by_id IS NOT NULL);
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_voided_check
                CHECK (status <> 'voided' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL));

            CREATE OR REPLACE FUNCTION invoice_records_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'invoice_records: el registro de facturación es de solo alta (%% no está permitido)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION invoice_records_chain() RETURNS trigger AS $$
            DECLARE
                last_id bigint;
                last_seq bigint;
                last_hash text;
                expected text;
            BEGIN
                SELECT id, seq, hash INTO last_id, last_seq, last_hash FROM invoice_records
                    WHERE sif_installation_id = NEW.sif_installation_id ORDER BY seq DESC LIMIT 1;
                IF last_id IS NULL THEN
                    IF NEW.seq <> 1 OR NEW.previous_hash <> '' OR NOT NEW.is_first OR NEW.previous_record_id IS NOT NULL THEN
                        RAISE EXCEPTION 'invoice_records: el primer registro de la instalación debe ser el 1, sin huella anterior';
                    END IF;
                ELSIF NEW.seq <> last_seq + 1 OR NEW.previous_hash <> last_hash OR NEW.is_first OR NEW.previous_record_id IS DISTINCT FROM last_id THEN
                    RAISE EXCEPTION 'invoice_records: el registro no sigue al último de la cadena (%%)', last_seq;
                END IF;
                expected := upper(encode(sha256(convert_to(%1$s, 'UTF8')), 'hex'));
                IF expected IS NULL OR NEW.hash <> expected THEN
                    RAISE EXCEPTION 'invoice_records: la huella no coincide con la de sus campos';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoice_records_chain BEFORE INSERT ON invoice_records
                FOR EACH ROW EXECUTE FUNCTION invoice_records_chain();
            CREATE TRIGGER invoice_records_append_only BEFORE UPDATE OR DELETE ON invoice_records
                FOR EACH ROW EXECUTE FUNCTION invoice_records_append_only();
            CREATE TRIGGER invoice_records_no_truncate BEFORE TRUNCATE ON invoice_records
                FOR EACH STATEMENT EXECUTE FUNCTION invoice_records_append_only();

            CREATE OR REPLACE FUNCTION sales_documents_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'sales_documents: las facturas no se borran';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'sales_documents: una factura se crea siempre como borrador';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'sales_documents: una factura emitida no se borra (se anula o se rectifica)';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                IF OLD.status IN ('cancelled', 'voided') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'sales_documents: una factura anulada no cambia de estado';
                END IF;
                IF OLD.status = 'issued' AND NEW.status NOT IN ('issued', 'cancelled', 'voided') THEN
                    RAISE EXCEPTION 'sales_documents: una factura emitida no vuelve a borrador';
                END IF;
                IF %2$s THEN
                    RAISE EXCEPTION 'sales_documents: una factura emitida no se cambia (solo se rectifica o se anula)';
                END IF;
                IF %3$s THEN
                    RAISE EXCEPTION 'sales_documents: el PDF archivado y la anulación no se cambian';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_documents_guard_row BEFORE INSERT OR UPDATE OR DELETE ON sales_documents
                FOR EACH ROW EXECUTE FUNCTION sales_documents_guard();
            CREATE TRIGGER sales_documents_no_truncate BEFORE TRUNCATE ON sales_documents
                FOR EACH STATEMENT EXECUTE FUNCTION sales_documents_guard();

            CREATE OR REPLACE FUNCTION sales_document_children_guard() RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION '%%: no se vacía', TG_TABLE_NAME;
                END IF;
                SELECT status INTO parent_status FROM sales_documents
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.sales_document_id ELSE NEW.sales_document_id END;
                IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                    RAISE EXCEPTION '%%: las líneas y el desglose de una factura emitida no se cambian', TG_TABLE_NAME;
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.sales_document_id IS DISTINCT FROM OLD.sales_document_id THEN
                    RAISE EXCEPTION '%%: una línea no cambia de factura', TG_TABLE_NAME;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_document_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_document_lines
                FOR EACH ROW EXECUTE FUNCTION sales_document_children_guard();
            CREATE TRIGGER sales_document_taxes_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_document_taxes
                FOR EACH ROW EXECUTE FUNCTION sales_document_children_guard();

            CREATE OR REPLACE FUNCTION numbering_counters_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'numbering_counters: los contadores no se borran';
                END IF;
                IF NEW.last_number < OLD.last_number OR NEW.series_id IS DISTINCT FROM OLD.series_id OR NEW.year IS DISTINCT FROM OLD.year THEN
                    RAISE EXCEPTION 'numbering_counters: un contador solo sube';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER numbering_counters_guard BEFORE UPDATE OR DELETE ON numbering_counters
                FOR EACH ROW EXECUTE FUNCTION numbering_counters_guard();
            SQL, self::PG_HASH_INPUT, $frozen, $once));
    }

    private static function installSqlite(): void
    {
        $frozen = implode("\n                    OR ", array_map(fn (string $column): string => "NEW.{$column} IS NOT OLD.{$column}", self::FROZEN));
        $once = implode("\n                    OR ", array_map(fn (string $column): string => "(OLD.{$column} IS NOT NULL AND NEW.{$column} IS NOT OLD.{$column})", self::ONCE));
        $last = '(SELECT %s FROM invoice_records WHERE sif_installation_id = NEW.sif_installation_id ORDER BY seq DESC LIMIT 1)';

        DB::unprepared(sprintf(<<<'SQL'
            CREATE TRIGGER invoice_records_no_update BEFORE UPDATE ON invoice_records
            BEGIN SELECT RAISE(ABORT, 'invoice_records: el registro de facturación es de solo alta'); END;

            CREATE TRIGGER invoice_records_no_delete BEFORE DELETE ON invoice_records
            BEGIN SELECT RAISE(ABORT, 'invoice_records: el registro de facturación es de solo alta'); END;

            CREATE TRIGGER invoice_records_chain BEFORE INSERT ON invoice_records
            WHEN (%1$s IS NULL AND (NEW.seq <> 1 OR NEW.previous_hash <> '' OR NEW.is_first = 0 OR NEW.previous_record_id IS NOT NULL))
                OR (%1$s IS NOT NULL AND (NEW.seq <> %2$s + 1 OR NEW.previous_hash <> %3$s OR NEW.is_first <> 0 OR NEW.previous_record_id IS NOT %1$s))
            BEGIN SELECT RAISE(ABORT, 'invoice_records: el registro no sigue al último de la cadena'); END;

            CREATE TRIGGER sales_documents_insert BEFORE INSERT ON sales_documents
            WHEN NEW.status <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_documents: una factura se crea siempre como borrador'); END;

            CREATE TRIGGER sales_documents_no_delete BEFORE DELETE ON sales_documents
            WHEN OLD.status <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_documents: una factura emitida no se borra (se anula o se rectifica)'); END;

            CREATE TRIGGER sales_documents_coherence BEFORE UPDATE ON sales_documents
            WHEN NEW.status NOT IN ('draft', 'issued', 'cancelled', 'voided')
                OR (NEW.status <> 'draft' AND (NEW.series_id IS NULL OR NEW.year IS NULL OR NEW.number IS NULL OR NEW.full_number IS NULL
                    OR NEW.issued_at IS NULL OR NEW.invoice_record_id IS NULL OR NEW.client_snapshot IS NULL OR NEW.issuer_snapshot IS NULL))
                OR (NEW.status = 'draft' AND (NEW.number IS NOT NULL OR NEW.full_number IS NOT NULL OR NEW.invoice_record_id IS NOT NULL))
                OR (NEW.type = 'credit_note' AND ((NEW.rectified_document_id IS NULL AND NEW.rectified_holded_invoice_id IS NULL)
                    OR NEW.rectification_kind IS NULL OR NEW.rectification_reason IS NULL))
                OR (NEW.status = 'cancelled' AND NEW.cancelled_by_id IS NULL)
                OR (NEW.status = 'voided' AND (NEW.voided_at IS NULL OR NEW.void_reason IS NULL))
            BEGIN SELECT RAISE(ABORT, 'sales_documents: datos de la factura incoherentes con su estado'); END;

            CREATE TRIGGER sales_documents_frozen BEFORE UPDATE ON sales_documents
            WHEN OLD.status <> 'draft' AND (
                (OLD.status IN ('cancelled', 'voided') AND NEW.status IS NOT OLD.status)
                OR (OLD.status = 'issued' AND NEW.status NOT IN ('issued', 'cancelled', 'voided'))
                OR %4$s
                OR %5$s)
            BEGIN SELECT RAISE(ABORT, 'sales_documents: una factura emitida no se cambia (solo se rectifica o se anula)'); END;

            CREATE TRIGGER sales_document_lines_insert BEFORE INSERT ON sales_document_lines
            WHEN (SELECT status FROM sales_documents WHERE id = NEW.sales_document_id) <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_document_lines: las líneas de una factura emitida no se cambian'); END;

            CREATE TRIGGER sales_document_lines_update BEFORE UPDATE ON sales_document_lines
            WHEN (SELECT status FROM sales_documents WHERE id = OLD.sales_document_id) <> 'draft' OR NEW.sales_document_id IS NOT OLD.sales_document_id
            BEGIN SELECT RAISE(ABORT, 'sales_document_lines: las líneas de una factura emitida no se cambian'); END;

            CREATE TRIGGER sales_document_lines_delete BEFORE DELETE ON sales_document_lines
            WHEN (SELECT status FROM sales_documents WHERE id = OLD.sales_document_id) <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_document_lines: las líneas de una factura emitida no se cambian'); END;

            CREATE TRIGGER sales_document_taxes_insert BEFORE INSERT ON sales_document_taxes
            WHEN (SELECT status FROM sales_documents WHERE id = NEW.sales_document_id) <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_document_taxes: el desglose de una factura emitida no se cambia'); END;

            CREATE TRIGGER sales_document_taxes_update BEFORE UPDATE ON sales_document_taxes
            WHEN (SELECT status FROM sales_documents WHERE id = OLD.sales_document_id) <> 'draft' OR NEW.sales_document_id IS NOT OLD.sales_document_id
            BEGIN SELECT RAISE(ABORT, 'sales_document_taxes: el desglose de una factura emitida no se cambia'); END;

            CREATE TRIGGER sales_document_taxes_delete BEFORE DELETE ON sales_document_taxes
            WHEN (SELECT status FROM sales_documents WHERE id = OLD.sales_document_id) <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'sales_document_taxes: el desglose de una factura emitida no se cambia'); END;

            CREATE TRIGGER numbering_counters_update BEFORE UPDATE ON numbering_counters
            WHEN NEW.last_number < OLD.last_number OR NEW.series_id IS NOT OLD.series_id OR NEW.year IS NOT OLD.year
            BEGIN SELECT RAISE(ABORT, 'numbering_counters: un contador solo sube'); END;

            CREATE TRIGGER numbering_counters_delete BEFORE DELETE ON numbering_counters
            BEGIN SELECT RAISE(ABORT, 'numbering_counters: los contadores no se borran'); END;
            SQL,
            sprintf($last, 'id'),
            sprintf($last, 'seq'),
            sprintf($last, 'hash'),
            $frozen,
            $once,
        ));
    }
}
