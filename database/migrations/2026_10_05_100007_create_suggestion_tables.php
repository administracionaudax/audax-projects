<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sugerencias (F-159 a F-170, entrega 10.7), con los mismos campos que WeeklySync.
 * - Los adjuntos de una propuesta o de un comentario son Attachments polimórficos
 *   (attachable = SuggestionPost o SuggestionComment).
 * - Una reacción por persona y comentario (thumbs_up, rocket, eyes o heart), como en WeeklySync.
 * - vote_count y comment_count son contadores desnormalizados para el orden Top y Trending.
 * - Se precargan el tablero «Sugerencias» y la categoría «Bugs» (F-169).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestion_boards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('suggestion_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['suggestion_board_id', 'slug']);
        });

        Schema::create('suggestion_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_board_id')->constrained()->restrictOnDelete();
            $table->foreignId('suggestion_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 160);
            $table->longText('body');
            $table->string('status', 16)->default('open');
            // Orden dentro de su columna del roadmap (F-168).
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('vote_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['suggestion_board_id', 'slug']);
            $table->index(['status', 'position']);
            $table->index('last_activity_at');
            $table->index('author_id');
        });

        Schema::create('suggestion_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['suggestion_post_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('suggestion_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('suggestion_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['suggestion_post_id', 'created_at']);
            $table->index('parent_id');
        });

        Schema::create('suggestion_comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reaction', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['suggestion_comment_id', 'user_id']);
        });

        // Historial de estados con nota oficial (F-167).
        Schema::create('suggestion_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_post_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['suggestion_post_id', 'created_at']);
        });

        $now = now();
        $board = DB::table('suggestion_boards')->insertGetId([
            'name' => 'Sugerencias',
            'slug' => 'sugerencias',
            'position' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('suggestion_categories')->insert([
            'suggestion_board_id' => $board,
            'name' => 'Bugs',
            'slug' => 'bugs',
            'position' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestion_status_events');
        Schema::dropIfExists('suggestion_comment_reactions');
        Schema::dropIfExists('suggestion_comments');
        Schema::dropIfExists('suggestion_votes');
        Schema::dropIfExists('suggestion_posts');
        Schema::dropIfExists('suggestion_categories');
        Schema::dropIfExists('suggestion_boards');
    }
};
