<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cómo se intentó entrar (D-165): `password` (correo y contraseña, lo de siempre) o `google`.
     * Las filas anteriores quedan como `password`.
     */
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->string('method', 16)->default('password')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
