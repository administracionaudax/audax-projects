<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Previsión (docs/PLAN-CARGAS.md §7.2 con las respuestas de §15; D-280 a D-289):
 *
 * - `forecast_projects`: el proyecto previsto. Cliente existente o nombre libre (`prospect_name`),
 *   seguridad solo «segura» o «posible» (P5 b: sin probabilidad), estado abierto, confirmado,
 *   perdido (con motivo) o vinculado. Al vincularse con el proyecto real (`project_id`, único), se
 *   guarda en `baseline` la foto congelada de lo estimado (D-286).
 * - `allocations`: asignaciones de horas sin tareas, de una persona **o** de un departamento sin
 *   persona («hueco»), en un proyecto real **o** en uno previsto, con cuatro modos (D-282). Son la
 *   única fuente de la carga de la previsión (P6 y P7, D-283).
 *
 * Minutos enteros; fechas locales en `date`; instantes en UTC. Las reglas «de uno u otro» van como
 * CHECK en PostgreSQL (SQLite no admite añadirlas a una tabla) y, siempre, en AllocationWriter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('prospect_name', 160)->nullable();
            $table->char('color', 7);
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('confidence', 12)->default('tentative');
            $table->string('status', 12)->default('open');
            $table->string('lost_reason', 200)->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->decimal('estimated_amount', 12, 2)->nullable();
            $table->foreignId('project_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('baseline')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'confidence']);
            $table->index(['start_date', 'end_date']);
            $table->index('client_id');
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->unsignedInteger('minutes')->nullable();
            $table->unsignedSmallInteger('percent')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('copied_from_allocation_id')->nullable()->constrained('allocations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['department_id', 'start_date', 'end_date']);
            $table->index('project_id');
            $table->index('forecast_project_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE allocations ADD CONSTRAINT allocations_one_container CHECK ((forecast_project_id IS NULL) <> (project_id IS NULL))');
            DB::statement('ALTER TABLE allocations ADD CONSTRAINT allocations_person_or_gap CHECK ((user_id IS NULL) <> (department_id IS NULL))');
            DB::statement('ALTER TABLE allocations ADD CONSTRAINT allocations_dates CHECK (end_date IS NULL OR end_date >= start_date)');
            DB::statement("ALTER TABLE allocations ADD CONSTRAINT allocations_amount CHECK ((mode = 'percent' AND percent IS NOT NULL) OR (mode <> 'percent' AND minutes IS NOT NULL))");
            DB::statement("ALTER TABLE allocations ADD CONSTRAINT allocations_open_end CHECK (end_date IS NOT NULL OR mode = 'monthly')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('forecast_projects');
    }
};
