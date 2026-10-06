<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Estoy fuera» de la Weekly (10.9b, D-228): el estado VACATION/ABSENT con fecha de vuelta de
 * WeeklySync (`users.status` y `status_end_date`). Lo pone la propia persona (o quien gestiona la
 * Weekly) y tiene efecto inmediato: exime de las weeklies cuyo plazo cae antes de la vuelta y quita
 * los recordatorios mientras dura. Sin fecha de vuelta, hasta que la persona lo quite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('weekly_away_reason', 16)->nullable()->after('job_title');
            $table->date('weekly_away_since')->nullable()->after('weekly_away_reason');
            $table->date('weekly_away_until')->nullable()->after('weekly_away_since');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_away_reason', 'weekly_away_since', 'weekly_away_until']);
        });
    }
};
