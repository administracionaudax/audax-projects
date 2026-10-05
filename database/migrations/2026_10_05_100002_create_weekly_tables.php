<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La Weekly (Fase 10, D-145 a D-150, contrato 10.1).
 *
 * Reglas de borrado:
 * - Las personas NUNCA se borran (se desactivan, D-037): todas las FK a users de autoría son
 *   restrictOnDelete, para que nada se lleve por delante una weekly (fallo de WeeklySync, D-145).
 *   Las de «quién lo hizo» (generó, cerró, editó) son nullOnDelete.
 * - Borrar una semana (F-069, solo `manage-weeklies`) borra su contenido: envíos, entradas,
 *   exenciones, audios y fotos de satisfacción van en cascada desde weekly_cycles.
 * - Los clientes se desactivan o se borran en suave: sus entradas los bloquean (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Semana de la weekly (F-070): de lunes a viernes, número Wnn-aa y etiqueta.
        Schema::create('weekly_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('number', 8)->unique();
            $table->string('label', 80);
            $table->date('start_date')->unique();
            $table->date('end_date');
            // Plazo (día) de entrega; a tiempo = antes del final de ese día en Europe/Madrid (F-100).
            $table->date('deadline_date');
            $table->string('status', 8)->default('active');
            // Informe estructurado (App\Domain\Weeklies\Report\WeeklyReport) y su texto final.
            $table->jsonb('report')->nullable();
            $table->longText('report_text')->nullable();
            $table->unsignedInteger('submission_count_at_generation')->nullable();
            $table->string('report_state', 8)->nullable();
            $table->text('report_error')->nullable();
            $table->timestamp('report_generated_at')->nullable();
            $table->foreignId('report_generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('report_edited_at')->nullable();
            $table->foreignId('report_edited_by')->nullable()->constrained('users')->nullOnDelete();
            // Audio completo (opcional; las secciones van en weekly_audio_sections).
            $table->string('audio_state', 8)->nullable();
            $table->text('audio_error')->nullable();
            $table->string('audio_disk', 32)->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamp('audio_generated_at')->nullable();
            $table->foreignId('audio_generated_by')->nullable()->constrained('users')->nullOnDelete();
            // Quién debía enviar, congelado al cerrar (los exentos van en weekly_exemptions).
            $table->json('expected_user_ids')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'start_date']);
        });

        // Una sola semana activa (F-070), como el índice único de WeeklySync.
        DB::statement("CREATE UNIQUE INDEX weekly_cycles_single_active ON weekly_cycles (status) WHERE status = 'active'");

        // Envío de una persona en una semana: borrador mientras submitted_at es nulo (D-150).
        Schema::create('weekly_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Primer envío: se conserva al reenviar (F-052) y decide si fue a tiempo.
            $table->timestamp('submitted_at')->nullable();
            // Último reenvío (null si no se ha reenviado).
            $table->timestamp('resubmitted_at')->nullable();
            // Último autoguardado del borrador (F-051).
            $table->timestamp('draft_saved_at')->nullable();
            $table->timestamps();

            $table->unique(['weekly_cycle_id', 'user_id']);
            $table->index(['user_id', 'submitted_at']);
        });

        // Apunte por cliente (D-150); client_id nulo = «General / Interno» (F-044).
        Schema::create('weekly_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->string('source', 10)->default('text');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Uno por cliente y envío. El de «General» (client_id nulo) lo garantiza el writer: en
            // PostgreSQL los nulos son distintos en un índice único.
            $table->unique(['weekly_submission_id', 'client_id']);
            $table->index('client_id');
            $table->index('project_id');
        });

        // Exenciones: manuales (F-038), renuncias a la exención por ausencia (F-053) y, al cerrar, la
        // foto de las ausencias que eximían (F-092).
        Schema::create('weekly_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reason', 8);
            $table->foreignId('absence_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['weekly_cycle_id', 'user_id']);
            $table->index('user_id');
        });

        // Locución del informe por secciones (F-084 a F-087).
        Schema::create('weekly_audio_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('kind', 8);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('script')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('voice', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['weekly_cycle_id', 'key']);
            $table->index('client_id');
        });

        // Satisfacción de cada cliente al cerrar cada semana (F-093, F-094 y F-132).
        Schema::create('client_satisfaction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('weekly_cycle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('previous_score')->nullable();
            $table->smallInteger('requested_delta')->nullable();
            $table->smallInteger('delta')->default(0);
            $table->string('rule', 40)->nullable();
            $table->text('reasoning')->nullable();
            $table->string('evidence_level', 8)->nullable();
            $table->boolean('explicit_client_impact')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('metrics')->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'weekly_cycle_id']);
            $table->index('weekly_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_satisfaction_snapshots');
        Schema::dropIfExists('weekly_audio_sections');
        Schema::dropIfExists('weekly_exemptions');
        Schema::dropIfExists('weekly_entries');
        Schema::dropIfExists('weekly_submissions');
        DB::statement('DROP INDEX IF EXISTS weekly_cycles_single_active');
        Schema::dropIfExists('weekly_cycles');
    }
};
