<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12, F1 (docs/PLAN-FASE-12.md; D-380 a D-399): lectura de Holded y «Vendido frente a real».
 * Solo aditiva y sin escribir nunca en Holded.
 *
 * - `client_billing_profiles`: la ficha fiscal del cliente (1:1). El NIF sigue en `clients.tax_id`.
 * - `projects` + el fee mensual (`monthly_minutes`, `monthly_fee_amount`) del tipo `monthly_fee`.
 * - `holded_*`: el espejo de solo lectura de Holded (contactos, proyectos, facturas y rectificativas
 *   con sus líneas, cobros, enlaces con proyectos y bolsas, y las ejecuciones de la sincronización).
 *   La correspondencia con los ids de Holded va además en `import_refs` (fuente `holded`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name', 200)->nullable();
            $table->string('eu_vat_number', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->char('country_code', 2)->default('ES');
            $table->string('tax_regime', 16)->default('general');
            $table->string('payment_method', 16)->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedTinyInteger('payment_day')->nullable();
            $table->string('language', 5)->default('es');
            $table->json('billing_emails')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('monthly_minutes')->nullable()->after('budget_minutes');
            $table->decimal('monthly_fee_amount', 12, 2)->nullable()->after('fixed_price_amount');
        });

        Schema::create('holded_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('holded_id', 64)->unique();
            $table->string('name', 255);
            $table->string('trade_name', 255)->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('tax_id_normalized', 32)->nullable()->index();
            $table->string('email', 255)->nullable();
            $table->string('type', 24)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->json('address')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // tax_id | name | manual; null sin casar.
            $table->string('match_method', 16)->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('holded_projects', function (Blueprint $table) {
            $table->id();
            $table->string('holded_id', 64)->unique();
            $table->string('name', 255);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('linked_manually')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('holded_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('holded_id', 64)->unique();
            $table->string('kind', 16);
            $table->string('number', 64)->nullable();
            $table->string('number_normalized', 64)->nullable()->index();
            $table->string('holded_contact_id', 64)->nullable()->index();
            $table->string('contact_name', 255)->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->char('currency', 3)->default('EUR');
            // Con signo: las rectificativas, en negativo (D-385).
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('pending_total', 12, 2)->default(0);
            $table->string('holded_status', 24)->nullable();
            $table->string('collection_status', 16)->default('unpaid');
            $table->boolean('is_draft')->default(false);
            $table->string('rectified_holded_id', 64)->nullable();
            $table->foreignId('rectified_invoice_id')->nullable()->constrained('holded_invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('pdf_fetched_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['issued_on', 'id']);
            $table->index(['client_id', 'issued_on']);
        });

        Schema::create('holded_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holded_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('units', 12, 4)->default(1);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('holded_project_id', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('holded_payments', function (Blueprint $table) {
            $table->id();
            $table->string('holded_id', 64)->unique();
            $table->string('holded_document_id', 64)->nullable()->index();
            $table->foreignId('holded_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            $table->string('method', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('holded_invoice_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holded_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'hour_bank_id']);
        });

        // Un enlace por factura, proyecto y bolsa (también sin bolsa): en PostgreSQL y SQLite.
        DB::statement('CREATE UNIQUE INDEX holded_invoice_links_unique ON holded_invoice_links (holded_invoice_id, project_id, COALESCE(hour_bank_id, 0))');

        Schema::create('holded_sync_runs', function (Blueprint $table) {
            $table->id();
            // schedule | manual | command | seeder
            $table->string('trigger', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('running');
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['started_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holded_sync_runs');
        Schema::dropIfExists('holded_invoice_links');
        Schema::dropIfExists('holded_payments');
        Schema::dropIfExists('holded_invoice_lines');
        Schema::dropIfExists('holded_invoices');
        Schema::dropIfExists('holded_projects');
        Schema::dropIfExists('holded_contacts');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['monthly_minutes', 'monthly_fee_amount']);
        });

        Schema::dropIfExists('client_billing_profiles');
    }
};
