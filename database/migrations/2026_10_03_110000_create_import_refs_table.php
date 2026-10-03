<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correspondencias de las importaciones (Fase 8, D-136): cada objeto de origen (una carpeta,
     * una lista, una tarea o un registro de horas de ClickUp) apunta al modelo local que creó. Con
     * ella, una segunda ejecución actualiza lo importado y añade lo nuevo sin duplicar nada.
     */
    public function up(): void
    {
        Schema::create('import_refs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('kind', 32);
            $table->string('external_id', 64);
            $table->string('local_type', 32);
            $table->unsignedBigInteger('local_id');
            $table->timestamps();

            $table->unique(['source', 'kind', 'external_id']);
            $table->index(['local_type', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_refs');
    }
};
