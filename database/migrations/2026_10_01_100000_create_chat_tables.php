<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat (SPEC §4.5 y §12, D-068 a D-072): conversaciones de proyecto (una por proyecto, con sus
     * miembros), directas 1:1 (una por pareja) y de grupo; mensajes de texto, audio, archivo y
     * sistema, con hilo, edición, borrado lógico, moderación, fijados y enlace a la tarea creada;
     * reacciones, menciones y la transcripción obligatoria de cada audio.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->foreignId('project_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('direct_key', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10);
            $table->text('body')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('system_key', 40)->nullable();
            $table->json('system_payload')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['conversation_id', 'id']);
            $table->index('user_id');
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamp('created_at')->nullable();
            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        Schema::create('message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('everyone')->default(false);
            $table->index('user_id');
        });

        Schema::create('audio_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending');
            $table->longText('text')->nullable();
            $table->string('language', 8)->nullable();
            $table->string('engine', 40)->nullable();
            $table->string('model', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedInteger('audio_duration_ms')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamp('admin_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_transcriptions');
        Schema::dropIfExists('message_mentions');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
