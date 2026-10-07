<?php

use App\Domain\Absences\LeaveCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 11, R3: vacaciones y permisos (PLAN-FASE-11 §7.6 y §8.3; D-360 a D-379). Solo aditiva; el
 * módulo `people` sigue apagado y /ausencias funciona como en la Fase 3 mientras lo esté.
 *
 * - `leave_types`: el catálogo de tipos de ausencia (LeaveCatalog::DEFAULTS precargado). Cada uno con
 *   su categoría de la Fase 3, que se sigue guardando en `absences.type`.
 * - `absences` + el tipo del catálogo (las de antes, con el de su categoría), la franja de las de
 *   horas, el primer nivel de aprobación (el segundo es la aprobación de siempre) y «Pedir
 *   cancelación».
 * - `leave_movements`: el libro de saldos, de **solo alta** (modelo y *trigger*) y sellado.
 * - `absence_documents`: los justificantes, en el disco privado.
 * - `leave_calendar_days`: días de media jornada y días bloqueados.
 * - `holidays` + nivel (nacional, autonómico, local o de empresa) y la fuente oficial.
 * - `absence_reminders`: qué aviso de saldo a punto de caducar o de justificante pendiente ya salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 16);
            $table->string('unit', 16);
            $table->text('description')->nullable();
            $table->string('legal_basis', 600)->nullable();
            $table->unsignedInteger('default_amount')->nullable();
            $table->unsignedInteger('travel_extra')->nullable();
            $table->boolean('paid')->default(true);
            $table->boolean('requires_document')->default(false);
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->boolean('health_data')->default(false);
            $table->unsignedInteger('annual_allowance')->nullable();
            $table->boolean('allowance_in_days')->default(false);
            $table->string('carry_over_until', 5)->nullable();
            $table->boolean('allow_without_balance')->default(true);
            $table->boolean('second_approval')->default(false);
            $table->boolean('respects_blocked_days')->default(false);
            $table->boolean('advisor_pending')->default(false);
            $table->string('advisor_note', 600)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (LeaveCatalog::DEFAULTS as $index => $definition) {
            DB::table('leave_types')->insert([...LeaveCatalog::row($definition, ($index + 1) * 10), 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::table('absences', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('type')->constrained('leave_types')->restrictOnDelete();
            $table->string('start_time', 5)->nullable()->after('partial_minutes');
            $table->string('end_time', 5)->nullable()->after('start_time');
            $table->foreignId('first_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->string('cancellation_status', 12)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->foreignId('cancellation_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancellation_decided_at')->nullable();
            $table->text('cancellation_comment')->nullable();
        });

        // Las ausencias de antes, con el tipo de su categoría (las cinco claves de siempre).
        foreach (DB::table('leave_types')->whereIn('key', ['vacation', 'sick', 'leave', 'training', 'other'])->pluck('id', 'key') as $key => $id) {
            DB::table('absences')->where('type', $key)->whereNull('leave_type_id')->update(['leave_type_id' => $id]);
        }

        Schema::create('leave_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 16);
            $table->integer('amount');
            $table->date('valid_from');
            $table->date('expires_on')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'leave_type_id', 'year']);
        });

        Schema::create('absence_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('absence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path', 300);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamp('created_at')->nullable();

            $table->index('absence_id');
        });

        Schema::create('leave_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('kind', 12);
            $table->string('name', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'start_date', 'end_date']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->string('level', 12)->nullable()->after('scope');
            $table->string('source', 300)->nullable()->after('level');
        });

        Schema::create('absence_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('key', 120);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');

            $table->unique(['kind', 'key']);
        });

        $this->guards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS leave_movements_append_only ON leave_movements;
                DROP TRIGGER IF EXISTS leave_movements_no_truncate ON leave_movements;
                DROP FUNCTION IF EXISTS leave_movements_append_only();
                SQL);
        }

        Schema::dropIfExists('absence_reminders');
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['level', 'source']);
        });
        Schema::dropIfExists('leave_calendar_days');
        Schema::dropIfExists('absence_documents');
        Schema::dropIfExists('leave_movements');
        Schema::table('absences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropConstrainedForeignId('first_approved_by');
            $table->dropConstrainedForeignId('cancellation_decided_by');
            $table->dropColumn(['start_time', 'end_time', 'first_approved_at', 'cancellation_status', 'cancellation_reason', 'cancellation_requested_at', 'cancellation_decided_at', 'cancellation_comment']);
        });
        Schema::dropIfExists('leave_types');
    }

    /**
     * El libro de saldos es de solo alta también en la base de datos: ni UPDATE ni DELETE (ni
     * TRUNCATE en PostgreSQL). Un saldo se corrige con un movimiento nuevo (D-363).
     */
    private function guards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION leave_movements_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'leave_movements: el libro de saldos es de solo alta, % no está permitido', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER leave_movements_append_only BEFORE UPDATE OR DELETE ON leave_movements
                    FOR EACH ROW EXECUTE FUNCTION leave_movements_append_only();
                CREATE TRIGGER leave_movements_no_truncate BEFORE TRUNCATE ON leave_movements
                    FOR EACH STATEMENT EXECUTE FUNCTION leave_movements_append_only();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER leave_movements_no_update BEFORE UPDATE ON leave_movements
                BEGIN SELECT RAISE(ABORT, 'leave_movements: el libro de saldos es de solo alta'); END;

                CREATE TRIGGER leave_movements_no_delete BEFORE DELETE ON leave_movements
                BEGIN SELECT RAISE(ABORT, 'leave_movements: el libro de saldos es de solo alta'); END;
                SQL);
        }
    }
};
