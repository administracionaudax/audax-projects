<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entradas de horas (SPEC §4.4). project_id y hour_bank_id se copian de la tarea al imputar
     * y no cambian si la tarea se mueve (SPEC §6). overage_minutes: minutos de la entrada que son
     * exceso sobre su bolsa (D-019). Índices del SPEC §15.
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('hour_bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedSmallInteger('overage_minutes')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->decimal('hourly_rate_snapshot', 10, 2)->nullable();
            $table->decimal('hourly_cost_snapshot', 10, 2)->nullable();
            $table->string('status', 12)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('time_entry_lock_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'user_id']);
            $table->index(['project_id', 'date']);
            $table->index(['hour_bank_id', 'date']);
            $table->index('task_id');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
