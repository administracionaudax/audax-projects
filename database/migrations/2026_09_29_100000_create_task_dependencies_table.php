<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dependencias fin-inicio entre tareas del mismo proyecto (SPEC §4.3 y §6.1, D-053). Sin ciclos
     * (lo impide App\Domain\Schedule\DependencyService).
     */
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('successor_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 20)->default('finish_to_start');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['predecessor_task_id', 'successor_task_id']);
            $table->index('successor_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
