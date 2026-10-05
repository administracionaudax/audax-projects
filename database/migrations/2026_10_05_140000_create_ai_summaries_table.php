<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resúmenes con IA de la ficha de cliente y de la ficha de persona (entrega 10.4, D-194): el último
 * de cada tipo para cada cliente o persona, que se guarda hasta que alguien lo regenera a mano.
 * - client_summary: «Resumen del cliente (IA)» (F-129), Markdown en `content`.
 * - client_team_activity: «Analizar actividad del equipo» (F-131), una frase por persona en `items`.
 * - person_performance: «Resumen de desempeño (IA)» (F-144), Markdown en `content`.
 * - person_client_activity: «Actividad por cliente (IA)» (F-145), una frase por cliente en `items`.
 * El estado (queued, running, done o failed) lo lleva el Job de la cola `ai`; el uso y el coste de cada
 * llamada van aparte, a ai_usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->morphs('subject');
            $table->string('state', 16);
            $table->text('content')->nullable();
            $table->json('items')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('model', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
