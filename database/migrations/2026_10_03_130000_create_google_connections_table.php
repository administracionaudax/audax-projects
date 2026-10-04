<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuenta de Google conectada por cada persona (Fase 9, D-142) para exportar informes a Google
     * Sheets. Los tokens se guardan cifrados con la APP_KEY (cast `encrypted`): en la base, y en sus
     * copias, nunca están en claro. Una conexión por persona.
     */
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_email');
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
