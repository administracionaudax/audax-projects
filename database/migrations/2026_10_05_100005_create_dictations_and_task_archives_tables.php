<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - dictations (D-152): dictado de la weekly por cliente (F-049) y de las notas de una tarea
 *   (F-060). Se transcribe con el mismo motor que el chat (TranscriptionService, Whisper en el
 *   servidor, D-146) en un Job de la cola `transcriptions`; el audio se borra al acabar, como en
 *   WeeklySync, que nunca lo guardaba. No es una AudioTranscription: aquella es 1:1 con un mensaje
 *   del chat y tiene su garantía de SPEC §12.
 * - task_archives (D-151): archivado PERSONAL de una tarea en «Mi espacio» (F-057). La tarea es
 *   compartida; archivarla solo la oculta de mi lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('context', 16);
            $table->foreignId('weekly_cycle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('audio_duration_ms')->nullable();
            // Transcripción literal de Whisper y texto limpio (F-171 y F-172).
            $table->longText('raw_text')->nullable();
            $table->longText('text')->nullable();
            $table->string('warning', 32)->nullable();
            $table->string('engine', 40)->nullable();
            $table->string('model', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('task_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamp('archived_at')->useCurrent();

            $table->unique(['user_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_archives');
        Schema::dropIfExists('dictations');
    }
};
