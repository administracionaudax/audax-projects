<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría visible (D-074) y retención (D-075): la tabla de spatie ya trae los índices de
     * log_name, (subject_type, subject_id) y (causer_type, causer_id). Faltan los que acotan por
     * fecha: el visor pagina por id y filtra por entidad, persona, acción y fechas, y
     * app:prune-data borra lo anterior a un instante. Los registros de acceso y las notificaciones
     * leídas también se borran por fecha.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at');
            $table->index(['causer_type', 'causer_id', 'created_at']);
            $table->index(['log_name', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        Schema::table('login_events', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['read_at']);
        });

        Schema::table('login_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['event', 'created_at']);
            $table->dropIndex(['log_name', 'created_at']);
            $table->dropIndex(['causer_type', 'causer_id', 'created_at']);
            $table->dropIndex(['created_at']);
        });
    }
};
