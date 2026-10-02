<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ausencias (SPEC §4.1): vacaciones, baja, permiso, formación externa u otro. Solo las
     * aprobadas restan capacidad: el día entero o partial_minutes.
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('partial_minutes')->nullable();
            $table->string('status', 12)->default('requested');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
