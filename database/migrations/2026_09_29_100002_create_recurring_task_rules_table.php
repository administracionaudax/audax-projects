<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tareas recurrentes (SPEC §4.3 y §14): plantilla de tarea más una regla semanal o mensual. Un
     * job diario crea las instancias (D-059). tasks.recurring_task_rule_id + occurrence_date impiden
     * duplicarlas.
     */
    public function up(): void
    {
        Schema::create('recurring_task_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('task_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->string('frequency', 8);
            $table->unsignedTinyInteger('interval')->default(1);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->unsignedTinyInteger('due_offset_days')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('last_generated_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'last_generated_on']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('recurring_task_rule_id')->nullable()->after('parent_task_id')->constrained()->nullOnDelete();
            $table->date('occurrence_date')->nullable()->after('recurring_task_rule_id');
            $table->unique(['recurring_task_rule_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['recurring_task_rule_id', 'occurrence_date']);
            $table->dropConstrainedForeignId('recurring_task_rule_id');
            $table->dropColumn('occurrence_date');
        });

        Schema::dropIfExists('recurring_task_rules');
    }
};
