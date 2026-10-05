<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tareas sugeridas por IA de «Mi espacio» (entrega 10.6, F-062, D-204): la última tanda de propuestas
 * de cada persona, sacadas de la última weekly cerrada (Job SuggestTasksFromWeekly en la cola `ai`).
 * Las propuestas NO son tareas: la persona las revisa (título, proyecto y bolsa obligatorios) y solo
 * entonces se crean con TaskWriter. Las creadas o descartadas salen de `items`; la siguiente tanda
 * sustituye a la anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_suggestion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 16);
            $table->json('items')->nullable();
            $table->unsignedSmallInteger('skipped')->default(0);
            $table->string('error', 500)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_suggestion_batches');
    }
};
