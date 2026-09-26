<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bloqueos de horas hechos por un admin por cliente o proyecto y rango de fechas
     * (SPEC §7, D-034). Permiten deshacer un bloqueo concreto y auditarlo.
     */
    public function up(): void
    {
        Schema::create('time_entry_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('entries_count')->default(0);
            $table->string('reference')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_locks');
    }
};
