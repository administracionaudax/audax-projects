<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orden de las tarjetas de Inicio de cada persona (D-138): lista ordenada de ids de tarjeta
     * (App\Domain\Home\HomeLayout::CARDS). Null = el orden por defecto.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('home_layout')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('home_layout');
        });
    }
};
