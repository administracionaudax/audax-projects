<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lectura del texto informativo de privacidad (SPEC §15, D-075): qué versión leyó cada persona
     * y cuándo. Si el admin cambia el texto, sube la versión y se vuelve a mostrar el aviso.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('privacy_acknowledged_version')->nullable()->after('notification_preferences');
            $table->timestamp('privacy_acknowledged_at')->nullable()->after('privacy_acknowledged_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_acknowledged_version', 'privacy_acknowledged_at']);
        });
    }
};
