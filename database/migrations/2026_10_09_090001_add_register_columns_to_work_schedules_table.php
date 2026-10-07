<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jornadas para el registro de jornada (Fase 11, R1; D-336). Cada versión de la jornada gana:
 * - el margen de entrada (horario tolerante de Woffu, W-028): `start_time_from` y `start_time_to`,
 * - la pausa prevista (la comida, W-033): `expected_pause_minutes`,
 * - la temporada de verano (W-030; el convenio de publicidad: 35 h del 1/7 al 31/8): del
 *   `summer_starts_on` al `summer_ends_on` (MM-DD, cada año) vale `summer_week` (7 valores en
 *   minutos, lunes primero) con su propia pausa prevista.
 * Van dentro de la versión, no en un perfil compartido: cambiar el verano es una versión nueva y la
 * jornada teórica de los veranos pasados no se reescribe (D-036).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->time('start_time_from')->nullable();
            $table->time('start_time_to')->nullable();
            $table->unsignedSmallInteger('expected_pause_minutes')->default(0);
            $table->char('summer_starts_on', 5)->nullable();
            $table->char('summer_ends_on', 5)->nullable();
            $table->json('summer_week')->nullable();
            $table->unsignedSmallInteger('summer_expected_pause_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'start_time_from',
                'start_time_to',
                'expected_pause_minutes',
                'summer_starts_on',
                'summer_ends_on',
                'summer_week',
                'summer_expected_pause_minutes',
            ]);
        });
    }
};
