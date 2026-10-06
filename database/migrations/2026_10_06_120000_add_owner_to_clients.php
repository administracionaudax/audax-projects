<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsable del cliente elegido a mano (10.9b, D-232; el `owner_id` del cliente de WeeklySync).
 * Null: se deduce de los proyectos (quien gestiona más proyectos abiertos, D-195).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('icon')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
