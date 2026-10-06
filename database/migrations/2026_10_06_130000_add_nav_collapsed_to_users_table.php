<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secciones plegadas de la barra lateral de cada persona (D-260): lista de ids de sección
     * (App\Domain\Navigation\NavSections::SECTIONS). Null = todas desplegadas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('nav_collapsed')->nullable()->after('home_layout');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nav_collapsed');
        });
    }
};
