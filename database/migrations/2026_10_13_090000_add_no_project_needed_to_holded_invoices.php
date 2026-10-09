<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «No necesita proyecto» (D-431): una factura que no va a ningún proyecto ni bolsa (gastos
 * repercutidos, una factura suelta) se marca para que deje de contar en «Sin proyecto», en el
 * contador de la barra lateral, en «Requiere atención» del Resumen y en la cobertura de «Por
 * revisar». La marca es de Audax: la sincronización con Holded no la toca (rellena solo los campos
 * que vienen de Holded). Las facturas propias de la emisión (E1) llevarán las mismas tres columnas
 * en su tabla (`MarksNoProjectNeeded`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holded_invoices', function (Blueprint $table) {
            $table->timestamp('no_project_needed_at')->nullable();
            $table->foreignId('no_project_needed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('no_project_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('holded_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('no_project_needed_by');
            $table->dropColumn(['no_project_needed_at', 'no_project_note']);
        });
    }
};
