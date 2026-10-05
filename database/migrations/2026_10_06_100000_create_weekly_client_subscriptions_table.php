<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Unirme a clientes» de la Weekly (D-221, sustituye a la membresía de D-156): una suscripción propia
 * de la Weekly, el `client_team_members` de WeeklySync. Solo hace que el cliente salga propuesto en
 * «Mi weekly», en «Mis clientes» y en el equipo del cliente de la Weekly. NO da acceso a nada del
 * proyecto (chat, horas, tareas ni bolsas): eso sigue siendo `project_members`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_client_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_client_subscriptions');
    }
};
