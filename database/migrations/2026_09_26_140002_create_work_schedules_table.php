<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jornada versionada (SPEC §4.1): para una fecha se usa el horario vigente en esa fecha.
     */
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                $table->unsignedSmallInteger("{$day}_minutes")->default(0);
            }
            $table->timestamps();

            $table->index(['user_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
