<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal de cliente (SPEC §11, D-063 a D-066):
     * - por cliente: cómo se nombra a las personas, qué estados de horas ve y si recibe avisos de
     *   sus bolsas por email,
     * - por proyecto: si el cliente ve el proyecto (tareas y estados), las horas por tarea y el Gantt.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('portal_person_display', 10)->default('name')->after('default_hourly_rate');
            $table->string('portal_entry_visibility', 10)->default('approved')->after('portal_person_display');
            $table->boolean('portal_notify_thresholds')->default(false)->after('portal_entry_visibility');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('portal_project_visible')->default(false);
            $table->boolean('portal_show_task_hours')->default(false);
            $table->boolean('portal_gantt_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['portal_project_visible', 'portal_show_task_hours', 'portal_gantt_visible']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['portal_person_display', 'portal_entry_visibility', 'portal_notify_thresholds']);
        });
    }
};
