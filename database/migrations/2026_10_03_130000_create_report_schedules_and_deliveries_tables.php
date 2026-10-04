<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Envío de informes por correo y envíos programados (Fase 9, D-141):
     * - report_schedules: qué informe, a quién y cuándo (en Europe/Madrid; next_run_at en UTC),
     * - report_deliveries: el historial de cada envío (también los «al momento», sin programación),
     * - report_downloads: los ficheros de más de 10 MB que salen como enlace firmado (7 días).
     */
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            // Los usuarios nunca se borran (SPEC §14): restrict.
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 200);
            $table->json('request');
            $table->json('formats');
            $table->string('relative_period', 16)->default('fixed');
            $table->json('recipient_user_ids');
            $table->json('recipient_emails');
            $table->string('subject', 150)->nullable();
            $table->text('message')->nullable();
            $table->string('frequency', 16);
            $table->timestamp('run_at')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->string('time', 5);
            $table->boolean('is_active')->default(true);
            $table->string('paused_reason', 32)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
            $table->index(['owner_user_id', 'created_at']);
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            // Al borrar la programación, su historial queda (y la auditoría): null.
            $table->foreignId('schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 200);
            $table->json('request');
            $table->json('formats');
            $table->json('recipient_user_ids');
            $table->json('recipient_emails');
            $table->string('subject', 150)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'created_at']);
            $table->index(['sender_user_id', 'created_at']);
        });

        Schema::create('report_downloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('delivery_id')->constrained('report_deliveries')->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('filename', 200);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_downloads');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedules');
    }
};
