<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan del día (docs/PLAN-CARGAS.md §7.1, D-250 a D-256): la cabecera de cada persona y día, sus
 * líneas de texto libre y los comentarios de su responsable. Además, el enlace opcional de los
 * temporizadores y las entradas de horas con la línea de la que salen (solo un enlace: no cambia
 * ninguna regla de TimeEntryWriter, de las bolsas ni de la aprobación).
 *
 * - `day_plans.reminded_at`: el recordatorio de la hora límite ya enviado ese día (D-252). Reclamarlo
 *   es una actualización atómica de la fila (nunca dos avisos el mismo día, aunque el comando se
 *   ejecute dos veces o cambie la hora).
 * - `day_plan_items.carry_count`: cuántas veces se ha pasado la línea de un día a otro (la marca
 *   «↻ ×N»), copiada al pasarla para no recorrer la cadena `carried_from_id` en cada consulta.
 * - Minutos enteros; fechas locales (Europe/Madrid) en `date`; instantes en UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->timestamp('published_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });

        Schema::create('day_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('text', 200);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('planned_minutes')->nullable();
            $table->string('status', 12)->default('pending');
            $table->timestamp('status_changed_at')->nullable();
            $table->string('not_done_reason', 200)->nullable();
            $table->foreignId('carried_from_id')->nullable()->constrained('day_plan_items')->nullOnDelete();
            $table->unsignedSmallInteger('carry_count')->default(0);
            $table->string('origin', 12)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['date', 'user_id']);
            $table->index(['user_id', 'status', 'date']);
            $table->index('task_id');
        });

        Schema::create('day_plan_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_plan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('day_plan_item_id');
        });

        Schema::table('active_timers', function (Blueprint $table) {
            $table->foreignId('day_plan_item_id')->nullable()->constrained('day_plan_items')->nullOnDelete();
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('day_plan_item_id')->nullable()->constrained('day_plan_items')->nullOnDelete();
            $table->index('day_plan_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['day_plan_item_id']);
            $table->dropConstrainedForeignId('day_plan_item_id');
        });

        Schema::table('active_timers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('day_plan_item_id');
        });

        Schema::dropIfExists('day_plan_comments');
        Schema::dropIfExists('day_plan_items');
        Schema::dropIfExists('day_plans');
    }
};
