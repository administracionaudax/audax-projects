<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avisos de bolsa ya enviados (SPEC §8.5 y §8.6, D-035): la clave única garantiza que cada
     * umbral avisa una sola vez por bolsa ("threshold:75") y el exceso, como máximo una vez al
     * día ("overage:2026-09-26").
     */
    public function up(): void
    {
        Schema::create('hour_bank_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hour_bank_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('threshold')->nullable();
            $table->date('notified_on');
            $table->string('key', 40);
            $table->timestamps();

            $table->unique(['hour_bank_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hour_bank_alerts');
    }
};
