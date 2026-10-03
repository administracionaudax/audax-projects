<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendario del equipo (D-144) y Mis tareas (D-143):
     * - el calendario acota las tareas por el rango visible: vencen en él, empiezan en él o lo
     *   cruzan (empiezan antes y vencen después). (due_date, start_date) sirve a la primera y a la
     *   última (vencen después del rango, con el inicio en el índice); start_date, a la segunda.
     *   Hasta ahora solo había (assignee_user_id, due_date), que no sirve para todo el equipo,
     * - Mis tareas ordena por la última imputación de cada persona en cada tarea:
     *   (user_id, task_id, date) responde «MAX(date) GROUP BY task_id» de una persona con el índice.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['due_date', 'start_date']);
            $table->index('start_date');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['user_id', 'task_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'task_id', 'date']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['start_date']);
            $table->dropIndex(['due_date', 'start_date']);
        });
    }
};
