<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens de las invitaciones de alta (broker «invitations», D-038): tabla propia, separada de
     * password_reset_tokens. Así un enlace de «he olvidado la contraseña» (60 minutos) no vale
     * 7 días en /invitacion, y pedir un restablecimiento no invalida la invitación de nadie.
     */
    public function up(): void
    {
        Schema::create('invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_tokens');
    }
};
