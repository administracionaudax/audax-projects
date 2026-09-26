<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bolsas de horas (SPEC §4.2 y §8). consumed_minutes y overage_minutes son una caché que
     * mantiene App\Domain\HourBanks\HourBankLedger (D-019, D-035).
     */
    public function up(): void
    {
        Schema::create('hour_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('total_minutes');
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('overage_policy', 8)->default('inherit');
            $table->foreignId('renewed_from_id')->nullable()->constrained('hour_banks')->nullOnDelete();
            $table->string('invoice_reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('consumed_minutes')->default(0);
            $table->unsignedInteger('overage_minutes')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('closed_remaining_minutes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('status');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hour_banks');
    }
};
