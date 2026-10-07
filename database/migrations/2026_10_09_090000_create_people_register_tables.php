<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de jornada (Fase 11, R1; docs/PLAN-FASE-11.md §0.2; D-332 y D-335). Art. 34.9 ET:
 * un registro diario de inicio y fin, fiable y que no se pueda alterar sin dejar rastro.
 *
 * - `clock_events`: **solo de alta**. Cada fichaje, y cada anulación de una corrección (fila
 *   `void`), es una fila nueva encadenada por persona (`seq`, `prev_hash`, `hash`). Un *trigger*
 *   rechaza UPDATE y DELETE (y TRUNCATE en PostgreSQL) en los dos motores: ni la app, ni el admin,
 *   ni quien entre en la base de datos con el usuario de la app puede reescribir el pasado sin
 *   quitar antes el *trigger*, y aun así la cadena de huellas lo delata (RegisterIntegrity).
 *   La persona tiene una FK *restrict*: mientras tenga fichajes, no se puede borrar.
 * - `clock_corrections`: las correcciones con doble conformidad. No se borran nunca y, una vez
 *   decididas (aceptada, en discrepancia o retirada), no se cambian: otro *trigger*. Mientras está
 *   pendiente solo cambian el estado y los campos de la decisión.
 * - `employment_profiles`: datos laborales 1:1 con la persona (alta, baja y si está sujeta al
 *   registro, D-331 y D-343). R2 y R3 añaden columnas aquí.
 * - `clock_reminders`: avisos de entrada, salida y jornada sin cerrar ya enviados (uno por persona,
 *   día de Madrid y tipo, D-339).
 *
 * Instantes en UTC; `date` es el día de Madrid de la jornada (el de su entrada, D-333).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clock_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            // Ids de los fichajes efectivos que anula y fichajes que añade (tipo, instante UTC y modo).
            $table->json('voids');
            $table->json('adds');
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            // Discrepancia: «rejected» (la otra parte no está de acuerdo) o «no_answer» (7 días).
            $table->string('dispute_reason', 12)->nullable();
            // Sello SHA-256 de la corrección decidida (RegisterHasher::correction()).
            $table->char('hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('clock_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->string('kind', 12);
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->string('work_mode', 10)->nullable();
            $table->string('pause_type', 12)->nullable();
            $table->string('source', 12);
            $table->foreignId('voided_event_id')->nullable()->constrained('clock_events')->restrictOnDelete();
            $table->foreignId('correction_id')->nullable()->constrained('clock_corrections')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 160)->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'seq']);
            $table->unique('voided_event_id');
            $table->index(['user_id', 'occurred_at']);
            $table->index('correction_id');
        });

        Schema::create('employment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->boolean('subject_to_register')->default(true);
            $table->string('register_exemption_reason', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('clock_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 20);
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'date', 'kind']);
        });

        $this->guards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('clock_reminders');
        Schema::dropIfExists('employment_profiles');
        Schema::dropIfExists('clock_events');
        Schema::dropIfExists('clock_corrections');
    }

    /**
     * Los *triggers* que hacen el registro de solo alta (D-332) y congelan las correcciones
     * decididas (D-335). PostgreSQL (el servidor y la CI) y SQLite (los tests en el Mac).
     */
    private function guards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION clock_events_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'clock_events es de solo alta: % no está permitido (art. 34.9 ET)', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER clock_events_no_update_delete
                    BEFORE UPDATE OR DELETE ON clock_events
                    FOR EACH ROW EXECUTE FUNCTION clock_events_append_only();

                CREATE TRIGGER clock_events_no_truncate
                    BEFORE TRUNCATE ON clock_events
                    FOR EACH STATEMENT EXECUTE FUNCTION clock_events_append_only();

                CREATE OR REPLACE FUNCTION clock_corrections_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
                        RAISE EXCEPTION 'clock_corrections: las correcciones no se borran';
                    END IF;
                    IF OLD.status <> 'pending' THEN
                        RAISE EXCEPTION 'clock_corrections: una corrección decidida no se cambia';
                    END IF;
                    IF NEW.user_id IS DISTINCT FROM OLD.user_id
                        OR NEW.date IS DISTINCT FROM OLD.date
                        OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by
                        OR NEW.reason IS DISTINCT FROM OLD.reason
                        OR NEW.voids::text IS DISTINCT FROM OLD.voids::text
                        OR NEW.adds::text IS DISTINCT FROM OLD.adds::text
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'clock_corrections: lo propuesto no se cambia';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER clock_corrections_guard_row
                    BEFORE UPDATE OR DELETE ON clock_corrections
                    FOR EACH ROW EXECUTE FUNCTION clock_corrections_guard();

                CREATE TRIGGER clock_corrections_no_truncate
                    BEFORE TRUNCATE ON clock_corrections
                    FOR EACH STATEMENT EXECUTE FUNCTION clock_corrections_guard();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER clock_events_no_update BEFORE UPDATE ON clock_events
                BEGIN SELECT RAISE(ABORT, 'clock_events es de solo alta: UPDATE no está permitido (art. 34.9 ET)'); END;

                CREATE TRIGGER clock_events_no_delete BEFORE DELETE ON clock_events
                BEGIN SELECT RAISE(ABORT, 'clock_events es de solo alta: DELETE no está permitido (art. 34.9 ET)'); END;

                CREATE TRIGGER clock_corrections_no_delete BEFORE DELETE ON clock_corrections
                BEGIN SELECT RAISE(ABORT, 'clock_corrections: las correcciones no se borran'); END;

                CREATE TRIGGER clock_corrections_frozen BEFORE UPDATE ON clock_corrections
                WHEN OLD.status <> 'pending'
                    OR NEW.user_id IS NOT OLD.user_id
                    OR NEW.date IS NOT OLD.date
                    OR NEW.proposed_by IS NOT OLD.proposed_by
                    OR NEW.reason IS NOT OLD.reason
                    OR NEW.voids IS NOT OLD.voids
                    OR NEW.adds IS NOT OLD.adds
                    OR NEW.created_at IS NOT OLD.created_at
                BEGIN SELECT RAISE(ABORT, 'clock_corrections: una corrección decidida o lo propuesto no se cambia'); END;
                SQL);
        }
    }

    private function dropGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS clock_events_no_update_delete ON clock_events;
                DROP TRIGGER IF EXISTS clock_events_no_truncate ON clock_events;
                DROP TRIGGER IF EXISTS clock_corrections_guard_row ON clock_corrections;
                DROP TRIGGER IF EXISTS clock_corrections_no_truncate ON clock_corrections;
                DROP FUNCTION IF EXISTS clock_events_append_only();
                DROP FUNCTION IF EXISTS clock_corrections_guard();
                SQL);
        }
    }
};
