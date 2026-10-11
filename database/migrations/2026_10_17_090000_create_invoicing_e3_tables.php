<?php

use App\Domain\Billing\Issuing\BillingDocumentsView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emisión propia, entrega E3 · Recurrentes, programadas y facturar desde el trabajo
 * (docs/PLAN-EMISION.md §3.1, §3.8, §4.2 y §9; D-450 en adelante). Solo aditiva; el módulo
 * `invoicing` sigue apagado.
 *
 * - `recurring_invoices` y `recurring_invoice_lines`: la plantilla de una factura recurrente (cliente,
 *   líneas con palabras dinámicas, periodicidad, día, inicio, fin o número de veces, borrador o emitir
 *   y enviar sola, periodos omitidos, pausa) y, si viene de Holded, su id y lo que se leyó.
 * - `recurring_invoice_runs`: el historial de lo generado u omitido, **uno por recurrente y periodo**
 *   (índice único): la tarea diaria y «Generar ahora» nunca duplican.
 * - `sales_documents` gana su recurrente y su periodo, la emisión programada (fecha, enviar al emitir,
 *   quién) y el error de una emisión automática. `sales_document_lines` gana el mes que factura una
 *   línea de fee (para saber qué meses están facturados).
 *
 * Las columnas nuevas de `sales_documents` y `sales_document_lines` van sin clave ajena en SQLite:
 * añadirla rehace la tabla y se perderían sus *triggers* (InvoicingGuards). En PostgreSQL, sí.
 * Las vistas de `billing_documents` se rehacen con la fecha de programación (vista «Programadas»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->nullOnDelete();
            // RecurringPeriodicity: monthly | bimonthly | quarterly | semiannual | annual | custom
            $table->string('periodicity', 12);
            // Meses entre una factura y la siguiente (1, 2, 3, 6, 12 o los de «otra»).
            $table->unsignedTinyInteger('interval_months');
            // Día del mes en que se genera (1 a 31; si el mes es más corto, su último día).
            $table->unsignedTinyInteger('day_of_month');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('max_occurrences')->nullable();
            $table->unsignedSmallInteger('occurrences_count')->default(0);
            // La próxima que toca (null: acabada).
            $table->date('next_run_on')->nullable();
            // draft | issue
            $table->string('mode', 8)->default('draft');
            $table->boolean('auto_send')->default(false);
            // Quién emite y envía cuando la recurrente lo hace sola (quien eligió «Emitir»).
            $table->foreignId('issue_as')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('series_id')->nullable()->constrained('numbering_series')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->foreignId('withholding_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('customer_reference', 120)->nullable();

            // Periodos omitidos («Omitir este mes»): fechas AAAA-MM-DD.
            $table->json('skipped_dates')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('last_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();

            // Importada de Holded (app:import-holded-recurring), en pausa hasta el corte.
            $table->string('holded_recurring_id', 64)->nullable()->unique();
            $table->json('holded_snapshot')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('next_run_on');
            $table->index('client_id');
        });

        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // item | text
            $table->string('kind', 8)->default('item');
            $table->foreignId('service_id')->nullable()->constrained('billing_services')->nullOnDelete();
            // Con palabras dinámicas: [mes], [trimestre], [año], [mes anterior]…
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit', 8)->default('unit');
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->timestamps();

            $table->index(['recurring_invoice_id', 'position']);
        });

        Schema::create('recurring_invoice_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            // El periodo (la fecha que tocaba): uno por recurrente.
            $table->date('period_on');
            // generated | skipped
            $table->string('status', 12);
            // schedule | manual
            $table->string('trigger', 12);
            $table->foreignId('sales_document_id')->nullable()->constrained()->nullOnDelete();
            // Generada pero no se pudo emitir o enviar sola (queda en borrador).
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_invoice_id', 'period_on']);
        });

        BillingDocumentsView::dropPayments();
        BillingDocumentsView::drop();

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('recurring_invoice_id')->nullable();
            $table->date('recurring_period_on')->nullable();
            // Programada (E3): un borrador que se emite solo ese día (y se envía, si se eligió).
            $table->date('scheduled_for')->nullable();
            $table->boolean('scheduled_send')->default(false);
            $table->unsignedBigInteger('scheduled_by')->nullable();
            // Por qué no se pudo emitir o enviar sola (programada o recurrente «Emitir»).
            $table->string('auto_issue_error', 500)->nullable();

            $table->index('recurring_invoice_id');
            $table->index('scheduled_for');
        });

        Schema::table('sales_document_lines', function (Blueprint $table) {
            // El mes que factura una línea de fee (el primer día), para no volver a pedirlo.
            $table->date('period_start')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_recurring_invoice_id_foreign FOREIGN KEY (recurring_invoice_id) REFERENCES recurring_invoices (id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_scheduled_by_foreign FOREIGN KEY (scheduled_by) REFERENCES users (id) ON DELETE SET NULL');
        }

        BillingDocumentsView::create();
        BillingDocumentsView::createPayments();
    }

    public function down(): void
    {
        BillingDocumentsView::dropPayments();
        BillingDocumentsView::drop();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_documents DROP CONSTRAINT IF EXISTS sales_documents_recurring_invoice_id_foreign');
            DB::statement('ALTER TABLE sales_documents DROP CONSTRAINT IF EXISTS sales_documents_scheduled_by_foreign');
        }

        Schema::table('sales_document_lines', function (Blueprint $table) {
            $table->dropColumn('period_start');
        });
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropIndex(['recurring_invoice_id']);
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn(['recurring_invoice_id', 'recurring_period_on', 'scheduled_for', 'scheduled_send', 'scheduled_by', 'auto_issue_error']);
        });

        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoice_lines');
        Schema::dropIfExists('recurring_invoices');

        BillingDocumentsView::create();
        BillingDocumentsView::createPayments();
    }
};
