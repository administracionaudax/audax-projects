<?php

use App\Domain\People\Retention\RegisterGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de jornada, entrega R2 (Fase 11; docs/PLAN-FASE-11.md §0.3; D-346 a D-359): acceso,
 * cierres e Inspección. Solo aditiva; el módulo `people` sigue apagado.
 *
 * - `month_closes`: el cierre mensual de cada persona, con los totales y el diario congelados
 *   (WorkdayCalculator), el PDF con su SHA-256, el punto de la cadena al que corresponde y la
 *   confirmación o el desacuerdo de la persona; la desconfirmación (motivo, quién y cuándo) deja esa
 *   versión cerrada y el siguiente cierre es una versión nueva. Lo congelado no se cambia nunca y no
 *   se borra (*trigger*).
 * - `overtime_decisions`: la clasificación del exceso de un día (hora extra o complementaria y
 *   flexibilidad) con su destino (compensar o pagar). Solo alta: una decisión nueva del mismo día
 *   sustituye a la anterior (`supersedes_id`).
 * - `time_balance_movements`: el saldo de horas, un libro de movimientos de solo alta.
 * - `register_anchors`: el ancla diaria (la última huella de cada persona y su resumen encadenado).
 * - `register_checkpoints`: dónde empieza la cadena de cada persona tras la supresión del mes 49.
 * - `employment_profiles` (+): tiempo parcial y retención por litigio.
 * - `people_documents` y `people_document_reads`: documento de implantación del registro y política
 *   de desconexión, con versión y lectura registrada.
 * - `inspection_accesses`: el acceso temporal de solo lectura de la Inspección (apagado).
 * - `people_exports`: cada fichero del registro que sale de la app, con su SHA-256.
 *
 * Los *triggers* de PostgreSQL se rehacen para que la supresión de app:prune-data (y solo ella,
 * RegisterGuards) pueda borrar; UPDATE y TRUNCATE siguen prohibidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('month_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12)->default('pending');
            $table->integer('worked_minutes');
            $table->integer('expected_minutes');
            $table->integer('difference_minutes');
            $table->integer('overtime_minutes')->default(0);
            $table->json('totals');
            $table->json('days');
            $table->unsignedInteger('register_seq')->nullable();
            $table->char('register_hash', 64)->nullable();
            $table->string('pdf_path', 255);
            $table->char('pdf_sha256', 64);
            $table->char('content_hash', 64);
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('disagreed_at')->nullable();
            $table->string('disagreement_note', 1000)->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'month', 'version']);
            $table->index(['month', 'status']);
        });

        Schema::create('overtime_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('hour_type', 14);
            $table->integer('excess_minutes');
            $table->integer('overtime_minutes');
            $table->integer('flex_minutes');
            $table->string('destination', 12)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('overtime_decisions')->restrictOnDelete();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'date']);
        });

        Schema::create('time_balance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->integer('minutes');
            $table->string('kind', 16);
            $table->string('reason', 500);
            $table->foreignId('overtime_decision_id')->nullable()->constrained('overtime_decisions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'date']);
        });

        Schema::create('register_anchors', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->json('heads');
            $table->unsignedInteger('events_count');
            $table->char('prev_digest', 64);
            $table->char('digest', 64);
            $table->boolean('verified_ok');
            $table->json('problems')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('register_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->char('hash', 64);
            $table->date('pruned_through');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('employment_profiles', function (Blueprint $table) {
            $table->boolean('part_time')->default(false);
            $table->boolean('legal_hold')->default(false);
            $table->string('legal_hold_reason', 300)->nullable();
            $table->timestamp('legal_hold_since')->nullable();
            $table->foreignId('legal_hold_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('people_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->unsignedSmallInteger('version');
            $table->string('title', 200);
            $table->text('body');
            $table->boolean('is_draft')->default(true);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['key', 'version']);
        });

        Schema::create('people_document_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('people_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['people_document_id', 'user_id']);
        });

        Schema::create('inspection_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('reference', 120)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('code_hash', 255);
            $table->json('scope_user_ids')->nullable();
            $table->date('scope_from');
            $table->date('scope_to');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('people_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspection_access_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('format', 8);
            $table->json('params');
            $table->string('filename', 200);
            $table->char('sha256', 64);
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->nullable();

            $table->index('sha256');
            $table->index('created_at');
        });

        $this->guards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('people_exports');
        Schema::dropIfExists('inspection_accesses');
        Schema::dropIfExists('people_document_reads');
        Schema::dropIfExists('people_documents');

        Schema::table('employment_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropColumn(['part_time', 'legal_hold', 'legal_hold_reason', 'legal_hold_since']);
        });

        Schema::dropIfExists('register_checkpoints');
        Schema::dropIfExists('register_anchors');
        Schema::dropIfExists('time_balance_movements');
        Schema::dropIfExists('overtime_decisions');
        Schema::dropIfExists('month_closes');
    }

    private function guards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $pruning = RegisterGuards::PG_PRUNING;

            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION clock_events_append_only() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND {$pruning} THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'clock_events es de solo alta: % no está permitido (art. 34.9 ET)', TG_OP;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION clock_corrections_guard() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND {$pruning} THEN
                        RETURN OLD;
                    END IF;
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
                \$\$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION register_append_only() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND {$pruning} THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION '%: es de solo alta, % no está permitido (art. 34.9 ET)', TG_TABLE_NAME, TG_OP;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION month_closes_guard() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND {$pruning} THEN
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
                        RAISE EXCEPTION 'month_closes: los cierres del registro no se borran';
                    END IF;
                    IF OLD.status IN ('reopened', 'superseded') THEN
                        RAISE EXCEPTION 'month_closes: un cierre desconfirmado o sustituido no se cambia';
                    END IF;
                    IF NEW.user_id IS DISTINCT FROM OLD.user_id
                        OR NEW.month IS DISTINCT FROM OLD.month
                        OR NEW.version IS DISTINCT FROM OLD.version
                        OR NEW.worked_minutes IS DISTINCT FROM OLD.worked_minutes
                        OR NEW.expected_minutes IS DISTINCT FROM OLD.expected_minutes
                        OR NEW.difference_minutes IS DISTINCT FROM OLD.difference_minutes
                        OR NEW.overtime_minutes IS DISTINCT FROM OLD.overtime_minutes
                        OR NEW.totals::text IS DISTINCT FROM OLD.totals::text
                        OR NEW.days::text IS DISTINCT FROM OLD.days::text
                        OR NEW.register_seq IS DISTINCT FROM OLD.register_seq
                        OR NEW.register_hash IS DISTINCT FROM OLD.register_hash
                        OR NEW.pdf_path IS DISTINCT FROM OLD.pdf_path
                        OR NEW.pdf_sha256 IS DISTINCT FROM OLD.pdf_sha256
                        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                        OR NEW.generated_at IS DISTINCT FROM OLD.generated_at
                        OR NEW.generated_by IS DISTINCT FROM OLD.generated_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR (OLD.confirmed_at IS NOT NULL AND NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at) THEN
                        RAISE EXCEPTION 'month_closes: lo congelado de un cierre no se cambia';
                    END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE TRIGGER month_closes_guard_row BEFORE UPDATE OR DELETE ON month_closes
                    FOR EACH ROW EXECUTE FUNCTION month_closes_guard();
                CREATE TRIGGER month_closes_no_truncate BEFORE TRUNCATE ON month_closes
                    FOR EACH STATEMENT EXECUTE FUNCTION month_closes_guard();

                CREATE TRIGGER overtime_decisions_append_only BEFORE UPDATE OR DELETE ON overtime_decisions
                    FOR EACH ROW EXECUTE FUNCTION register_append_only();
                CREATE TRIGGER overtime_decisions_no_truncate BEFORE TRUNCATE ON overtime_decisions
                    FOR EACH STATEMENT EXECUTE FUNCTION register_append_only();

                CREATE TRIGGER time_balance_movements_append_only BEFORE UPDATE OR DELETE ON time_balance_movements
                    FOR EACH ROW EXECUTE FUNCTION register_append_only();
                CREATE TRIGGER time_balance_movements_no_truncate BEFORE TRUNCATE ON time_balance_movements
                    FOR EACH STATEMENT EXECUTE FUNCTION register_append_only();

                CREATE TRIGGER register_anchors_append_only BEFORE UPDATE OR DELETE ON register_anchors
                    FOR EACH ROW EXECUTE FUNCTION register_append_only();
                CREATE TRIGGER register_anchors_no_truncate BEFORE TRUNCATE ON register_anchors
                    FOR EACH STATEMENT EXECUTE FUNCTION register_append_only();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER month_closes_frozen BEFORE UPDATE ON month_closes
                WHEN OLD.status IN ('reopened', 'superseded')
                    OR NEW.user_id IS NOT OLD.user_id
                    OR NEW.month IS NOT OLD.month
                    OR NEW.version IS NOT OLD.version
                    OR NEW.worked_minutes IS NOT OLD.worked_minutes
                    OR NEW.expected_minutes IS NOT OLD.expected_minutes
                    OR NEW.difference_minutes IS NOT OLD.difference_minutes
                    OR NEW.overtime_minutes IS NOT OLD.overtime_minutes
                    OR NEW.totals IS NOT OLD.totals
                    OR NEW.days IS NOT OLD.days
                    OR NEW.register_seq IS NOT OLD.register_seq
                    OR NEW.register_hash IS NOT OLD.register_hash
                    OR NEW.pdf_path IS NOT OLD.pdf_path
                    OR NEW.pdf_sha256 IS NOT OLD.pdf_sha256
                    OR NEW.content_hash IS NOT OLD.content_hash
                    OR NEW.generated_at IS NOT OLD.generated_at
                    OR NEW.generated_by IS NOT OLD.generated_by
                    OR NEW.created_at IS NOT OLD.created_at
                    OR (OLD.confirmed_at IS NOT NULL AND NEW.confirmed_at IS NOT OLD.confirmed_at)
                BEGIN SELECT RAISE(ABORT, 'month_closes: lo congelado de un cierre no se cambia'); END;

                CREATE TRIGGER overtime_decisions_no_update BEFORE UPDATE ON overtime_decisions
                BEGIN SELECT RAISE(ABORT, 'overtime_decisions: es de solo alta'); END;

                CREATE TRIGGER time_balance_movements_no_update BEFORE UPDATE ON time_balance_movements
                BEGIN SELECT RAISE(ABORT, 'time_balance_movements: es de solo alta'); END;

                CREATE TRIGGER register_anchors_no_update BEFORE UPDATE ON register_anchors
                BEGIN SELECT RAISE(ABORT, 'register_anchors: es de solo alta'); END;
                SQL);

            RegisterGuards::installSqliteDeleteTriggers();
        }
    }

    private function dropGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS month_closes_guard_row ON month_closes;
                DROP TRIGGER IF EXISTS month_closes_no_truncate ON month_closes;
                DROP TRIGGER IF EXISTS overtime_decisions_append_only ON overtime_decisions;
                DROP TRIGGER IF EXISTS overtime_decisions_no_truncate ON overtime_decisions;
                DROP TRIGGER IF EXISTS time_balance_movements_append_only ON time_balance_movements;
                DROP TRIGGER IF EXISTS time_balance_movements_no_truncate ON time_balance_movements;
                DROP TRIGGER IF EXISTS register_anchors_append_only ON register_anchors;
                DROP TRIGGER IF EXISTS register_anchors_no_truncate ON register_anchors;
                DROP FUNCTION IF EXISTS month_closes_guard();
                DROP FUNCTION IF EXISTS register_append_only();
                SQL);
        }
    }
};
