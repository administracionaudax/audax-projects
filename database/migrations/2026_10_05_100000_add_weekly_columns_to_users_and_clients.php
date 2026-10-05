<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10 (D-145, contrato 10.1):
 * - users.job_title: el «Puesto» de WeeklySync (F-026, F-027 y F-138),
 * - clients.icon: el emoji del cliente (F-126),
 * - clients.satisfaction_score: satisfacción actual de 0 a 100, 50 por defecto (F-096). El histórico
 *   va en client_satisfaction_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->after('department_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('icon', 16)->nullable()->after('name');
            $table->unsignedSmallInteger('satisfaction_score')->default(50)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['icon', 'satisfaction_score']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
