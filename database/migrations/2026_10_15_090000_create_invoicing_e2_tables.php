<?php

use App\Domain\Billing\Issuing\BillingDocumentsView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emisión propia, entrega E2 · Cobrar, enviar y portal (docs/PLAN-EMISION.md §3.3 a §3.5, §4.2 y
 * §9; D-435 a D-446). Solo aditiva; el módulo `invoicing` sigue apagado.
 *
 * - `payments`: los cobros de las facturas propias (con signo: una devolución en una rectificativa
 *   va en negativo). No se borran: se anulan con motivo. `sales_documents.paid_total` es su caché.
 * - `sales_document_emails`: cada envío por email (factura, rectificativa o recordatorio) con sus
 *   destinatarios, el asunto y el texto tal como salieron, la huella del PDF y si el cliente abrió el
 *   enlace firmado.
 * - `email_templates`: las plantillas cambiadas en Ajustes (sin fila, la de serie de lang/es).
 * - `payment_reminder_rules` y `payment_reminder_deliveries`: los recordatorios por escalones, uno
 *   por factura y escalón.
 * - `sales_documents` gana el envío y las vistas del cliente (no son fiscales: el *trigger* las deja
 *   cambiar en una emitida).
 * - `client_billing_profiles` gana «Facturas en el portal» (apagado) y «Recordatorios de cobro»
 *   (encendido) por cliente; `attachments`, «Visible para el cliente».
 * - La vista `billing_payments` une los cobros de Holded y los propios (Resumen, por fecha de cobro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->restrictOnDelete();
            $table->date('paid_on');
            // Con signo: una devolución (en una rectificativa), en negativo.
            $table->decimal('amount', 12, 2);
            // BillingPaymentMethod: transfer | direct_debit | card | cash | other
            $table->string('method', 16);
            $table->string('bank_reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['sales_document_id', 'paid_on']);
            $table->index('paid_on');
        });

        Schema::create('payment_reminder_rules', function (Blueprint $table) {
            $table->id();
            // Días respecto al vencimiento: negativos, antes; 0, el día; positivos, después.
            $table->smallInteger('days');
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_document_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->restrictOnDelete();
            // send | reminder
            $table->string('kind', 12);
            // invoice | credit_note | reminder
            $table->string('template', 16);
            $table->char('language', 2)->default('es');
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject', 255);
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->string('message_id', 255)->nullable();
            // queued | sent | failed
            $table->string('status', 12)->default('queued');
            $table->string('error', 500)->nullable();
            // En modo de prueba (D-239) solo le llega a quien lo envía.
            $table->boolean('test_mode')->default(false);
            $table->foreignId('reminder_rule_id')->nullable()->constrained('payment_reminder_rules')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['sales_document_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('payment_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('rule_id')->constrained('payment_reminder_rules')->restrictOnDelete();
            $table->foreignId('email_id')->nullable()->constrained('sales_document_emails')->nullOnDelete();
            // sent | failed
            $table->string('status', 12);
            $table->string('detail', 255)->nullable();
            $table->timestamps();

            $table->unique(['sales_document_id', 'rule_id']);
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // invoice | credit_note | reminder
            $table->string('kind', 16);
            $table->char('language', 2);
            $table->string('subject', 255);
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'language']);
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
        });

        Schema::table('client_billing_profiles', function (Blueprint $table) {
            $table->boolean('portal_invoices')->default(false);
            $table->boolean('payment_reminders')->default(true);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->boolean('client_visible')->default(false);
        });

        // Escalones de partida (PLAN-EMISION §3.3): 3 días antes, el día, y 7 y 30 días después.
        $now = now();
        DB::table('payment_reminder_rules')->insert(array_map(
            fn (int $days): array => ['days' => $days, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            [-3, 0, 7, 30],
        ));

        BillingDocumentsView::createPayments();
    }

    public function down(): void
    {
        BillingDocumentsView::dropPayments();

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('client_visible');
        });
        Schema::table('client_billing_profiles', function (Blueprint $table) {
            $table->dropColumn(['portal_invoices', 'payment_reminders']);
        });
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'first_viewed_at', 'last_viewed_at', 'view_count']);
        });

        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('payment_reminder_deliveries');
        Schema::dropIfExists('sales_document_emails');
        Schema::dropIfExists('payment_reminder_rules');
        Schema::dropIfExists('payments');
    }
};
