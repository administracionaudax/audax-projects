<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recordatorios de la weekly (F-101 a F-110, entrega 10.5).
 * - weekly_reminder_rules: reglas día y hora (Madrid) por canal; sustituyen a email_reminders y
 *   web_notification_reminders de WeeklySync (el «web» pasa a Web Push).
 * - weekly_reminder_logs: registro de cada envío (enviado, fallido u omitido, con el error) y
 *   deduplicación por (trigger_key, canal, persona), como email_log.trigger_key.
 * Las plantillas (automatic, manual y weekly_closed) caben en `settings` (D-151).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 8);
            // Día ISO: 1 = lunes … 7 = domingo (WeeklySync usaba 0 = domingo; el importador convierte).
            $table->unsignedTinyInteger('day_of_week');
            // "HH:MM" en Europe/Madrid.
            $table->string('time', 5);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'day_of_week']);
        });

        Schema::create('weekly_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Copia de quién lo recibió (el registro sobrevive aunque cambie la persona).
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('template', 16);
            $table->string('channel', 8);
            $table->string('trigger_key', 120);
            $table->string('status', 8);
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['trigger_key', 'channel', 'user_id']);
            $table->index(['weekly_cycle_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reminder_logs');
        Schema::dropIfExists('weekly_reminder_rules');
    }
};
