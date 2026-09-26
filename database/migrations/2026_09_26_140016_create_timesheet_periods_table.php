<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semana (lunes a domingo) de cada usuario con su estado de envío y aprobación (SPEC §4.4,
     * D-020, D-034). Si no existe fila, la semana está abierta.
     */
    public function up(): void
    {
        Schema::create('timesheet_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 12)->default('open');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
            $table->index(['status', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_periods');
    }
};
