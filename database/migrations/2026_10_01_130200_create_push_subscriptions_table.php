<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web Push (SPEC §12 y §13, D-072): una fila por navegador suscrito. El endpoint es la URL del
     * servicio de push del navegador (FCM, Mozilla, Apple, Windows); se busca por su hash porque
     * puede ser largo. session_hash permite borrar la suscripción al cerrar sesión en ese navegador.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->char('session_hash', 64)->nullable()->index();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
