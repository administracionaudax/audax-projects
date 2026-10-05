<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso de la IA externa (D-146, F-173 y F-180): cada llamada a Gemini o a Google TTS, con el modelo,
 * los tokens o caracteres, la latencia y el coste estimado. Solo inserciones; la página «Uso de IA»
 * la lee por fechas. Sin textos de las weeklies: nunca se guarda el prompt ni la respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16);
            $table->string('model', 64);
            $table->string('feature', 32);
            $table->string('operation', 64)->nullable();
            $table->string('status', 8);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('response_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('character_count')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->nullableMorphs('subject');
            $table->string('error', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
