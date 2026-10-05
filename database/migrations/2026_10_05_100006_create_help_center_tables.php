<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de ayuda (F-148 a F-158, entrega 10.7), con los mismos campos que WeeklySync.
 * - El manual en PDF y el enlace de soporte (help_settings de WeeklySync) van en `settings`
 *   (`help_manual` y `help_support_url`, D-151).
 * - El vídeo de un tutorial es un Attachment polimórfico (attachable = HelpTutorial).
 * - Los «me gusta» van a una novedad automática (versión) o a una actualización manual: morph.
 * - El contenido lo gestiona `manage-weeklies` (D-147); los «me gusta» son de cada persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Novedades automáticas: una versión por semana, V.serie.mes.semana (F-150).
        Schema::create('help_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('major_version');
            $table->unsignedTinyInteger('month_number');
            $table->unsignedTinyInteger('week_of_month');
            $table->text('summary');
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['major_version', 'month_number', 'week_of_month']);
        });

        Schema::create('help_release_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_release_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['help_release_id', 'position']);
        });

        // Actualizaciones puntuales a mano (F-151).
        Schema::create('help_manual_updates', function (Blueprint $table) {
            $table->id();
            $table->date('published_on');
            $table->string('title');
            $table->string('subtitle');
            $table->longText('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('published_on');
        });

        // «Me gusta» en las novedades (F-153).
        Schema::create('help_update_likes', function (Blueprint $table) {
            $table->id();
            $table->morphs('likeable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['likeable_type', 'likeable_id', 'user_id']);
            $table->index('user_id');
        });

        // Tutoriales en vídeo, ligados opcionalmente a una versión (F-155).
        Schema::create('help_tutorials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('help_release_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('position');
        });

        // Preguntas frecuentes por secciones (F-156).
        Schema::create('help_faq_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('help_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_faq_section_id')->constrained()->restrictOnDelete();
            $table->text('question');
            $table->longText('answer');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['help_faq_section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_faqs');
        Schema::dropIfExists('help_faq_sections');
        Schema::dropIfExists('help_tutorials');
        Schema::dropIfExists('help_update_likes');
        Schema::dropIfExists('help_manual_updates');
        Schema::dropIfExists('help_release_changes');
        Schema::dropIfExists('help_releases');
    }
};
