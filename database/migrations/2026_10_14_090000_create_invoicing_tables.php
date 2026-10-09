<?php

use App\Domain\Billing\Issuing\InvoicingGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emisión propia de facturas, entrega E1 (docs/PLAN-EMISION.md §4 y §9; D-417 a D-429). Solo
 * aditiva; el módulo `invoicing` sigue apagado.
 *
 * - Catálogo y ajustes: `tax_rates` (IVA y retenciones, con su calificación de VeriFactu y su
 *   mención en el PDF), `payment_methods`, `billing_services` (el catálogo de servicios).
 * - Numeración: `sif_installations` (cada instalación tiene su cadena de registros), `numbering_series`
 *   (F, CN, PRU y PRUCN) y `numbering_counters` (último número por serie y año; solo sube).
 * - Ventas: `sales_documents` (facturas y rectificativas, con las copias del emisor y del cliente),
 *   `sales_document_lines`, `sales_document_taxes` (el desglose congelado), `sales_document_links`
 *   (proyecto y bolsa, como `holded_invoice_links`) y `sales_document_time_entry` (horas facturadas).
 * - `invoice_records`: el registro encadenado con la huella de VeriFactu, de solo alta.
 * - `time_entry_locks` gana `sales_document_id`: el bloqueo de las horas de una factura.
 *
 * Los *triggers* (InvoicingGuards) hacen que lo emitido no se pueda cambiar ni borrar, también a
 * mano, y que la cadena de registros no se pueda romper (PostgreSQL recalcula la huella).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            // vat | withholding
            $table->string('kind', 12)->default('vat');
            $table->string('name', 120);
            $table->decimal('rate', 5, 2);
            // S1, S2, N1, N2, E1…E6 (solo IVA)
            $table->string('operation_type', 4)->nullable();
            $table->string('legal_mention', 500)->nullable();
            $table->string('legal_mention_en', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // Texto del PDF; «:iban» se sustituye por el IBAN.
            $table->string('document_text', 500)->nullable();
            $table->string('document_text_en', 500)->nullable();
            $table->string('iban', 42)->nullable();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            // hour | unit | month
            $table->string('unit', 8)->default('unit');
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->string('category', 24)->nullable();
            $table->string('holded_service_id', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sif_installations', function (Blueprint $table) {
            $table->id();
            $table->char('sif_code', 2);
            $table->string('sif_name', 120);
            $table->string('installation_number', 20)->unique();
            // production | test
            $table->string('environment', 12);
            // chain_only | verifactu
            $table->string('mode', 16)->default('chain_only');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Se toca al emitir: la fila hace de candado de la cadena (también en SQLite).
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('numbering_series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 120);
            // invoice | credit_note
            $table->string('document_type', 16);
            $table->string('format', 32);
            $table->foreignId('refund_series_id')->nullable()->constrained('numbering_series')->restrictOnDelete();
            // regular | test | external
            $table->string('kind', 12)->default('regular');
            $table->foreignId('sif_installation_id')->nullable()->constrained('sif_installations')->restrictOnDelete();
            // Desde qué día se puede emitir con ella (F y CN: el corte, 1/1/2027, D-419).
            $table->date('starts_on')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('numbering_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            // El primero del año (1, o el siguiente al último de Holded en un corte a mitad de año, §7.1).
            $table->unsignedInteger('first_number')->default(1);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['series_id', 'year']);
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 16);
            $table->string('status', 12)->default('draft');
            $table->boolean('is_test')->default(false);

            $table->foreignId('series_id')->nullable()->constrained('numbering_series')->restrictOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('full_number', 32)->nullable()->unique();

            $table->date('issue_date');
            $table->date('operation_date')->nullable();
            $table->date('due_date')->nullable();

            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('client_name', 200);
            $table->json('client_snapshot')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->char('language', 2)->default('es');

            // Con signo: las rectificativas, en negativo.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('withholding_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->foreignId('withholding_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->decimal('withholding_rate', 5, 2)->nullable();

            $table->text('body')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('customer_reference', 120)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->text('payment_text')->nullable();

            $table->foreignId('rectified_document_id')->nullable()->constrained('sales_documents')->restrictOnDelete();
            $table->foreignId('rectified_holded_invoice_id')->nullable()->constrained('holded_invoices')->restrictOnDelete();
            $table->string('rectification_kind', 16)->nullable();
            $table->string('rectification_reason', 500)->nullable();
            $table->string('rectification_code', 2)->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('sales_documents')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignId('source_holded_invoice_id')->nullable()->constrained('holded_invoices')->nullOnDelete();

            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            // El registro de alta (sin clave ajena: el registro apunta a la factura).
            $table->unsignedBigInteger('invoice_record_id')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            // «No necesita proyecto» (D-431, MarksNoProjectNeeded): no es fiscal, se cambia emitida.
            $table->timestamp('no_project_needed_at')->nullable();
            $table->foreignId('no_project_needed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('no_project_note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series_id', 'year', 'number']);
            $table->index(['status', 'issue_date']);
            $table->index(['client_id', 'issue_date']);
        });

        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // item | text
            $table->string('kind', 8)->default('item');
            $table->foreignId('service_id')->nullable()->constrained('billing_services')->nullOnDelete();
            // Copia del servicio (nombre y código), para los informes como las líneas de Holded.
            $table->string('name', 200)->nullable();
            $table->string('service_code', 32)->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit', 8)->default('unit');
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('operation_type', 4)->nullable();
            $table->decimal('line_base', 12, 2)->default(0);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->nullOnDelete();
            // manual | hours | hour_bank | overage | fee
            $table->string('origin', 12)->default('manual');
            $table->unsignedInteger('minutes')->nullable();
            $table->timestamps();

            $table->index(['sales_document_id', 'position']);
        });

        Schema::create('sales_document_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->string('operation_type', 4);
            $table->decimal('rate', 5, 2);
            $table->decimal('base', 12, 2);
            $table->decimal('tax', 12, 2);
            $table->string('legal_mention', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sales_document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->cascadeOnDelete();
            // manual | rectified
            $table->string('method', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'hour_bank_id']);
        });
        DB::statement('CREATE UNIQUE INDEX sales_document_links_unique ON sales_document_links (sales_document_id, project_id, COALESCE(hour_bank_id, 0))');

        Schema::create('sales_document_time_entry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_entry_id')->constrained()->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
        // Una entrada solo puede estar en una factura viva (PLAN-EMISION §3.8).
        DB::statement('CREATE UNIQUE INDEX sales_document_time_entry_live ON sales_document_time_entry (time_entry_id) WHERE released_at IS NULL');

        Schema::create('invoice_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sif_installation_id')->constrained('sif_installations')->restrictOnDelete();
            $table->unsignedBigInteger('seq');
            // alta | anulacion
            $table->string('kind', 12);
            $table->foreignId('sales_document_id')->constrained()->restrictOnDelete();
            // Los campos de la huella, como irán en el XML (texto exacto).
            $table->string('issuer_tax_id', 32);
            $table->string('invoice_number', 60);
            $table->char('issue_date_text', 10);
            $table->string('invoice_type', 4)->nullable();
            $table->string('tax_total_text', 32)->nullable();
            $table->string('total_text', 32)->nullable();
            $table->string('previous_hash', 64)->default('');
            $table->string('generated_at_text', 32);
            $table->boolean('is_first')->default(false);
            $table->foreignId('previous_record_id')->nullable()->constrained('invoice_records')->restrictOnDelete();
            $table->char('hash', 64);
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['sif_installation_id', 'seq']);
            $table->index('sales_document_id');
        });

        Schema::table('time_entry_locks', function (Blueprint $table) {
            $table->foreignId('sales_document_id')->nullable()->constrained()->restrictOnDelete();
        });

        InvoicingGuards::install();
    }

    public function down(): void
    {
        InvoicingGuards::drop();

        Schema::table('time_entry_locks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_document_id');
        });

        Schema::dropIfExists('invoice_records');
        Schema::dropIfExists('sales_document_time_entry');
        Schema::dropIfExists('sales_document_links');
        Schema::dropIfExists('sales_document_taxes');
        Schema::dropIfExists('sales_document_lines');
        Schema::dropIfExists('sales_documents');
        Schema::dropIfExists('numbering_counters');
        Schema::dropIfExists('numbering_series');
        Schema::dropIfExists('sif_installations');
        Schema::dropIfExists('billing_services');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_rates');
    }
};
