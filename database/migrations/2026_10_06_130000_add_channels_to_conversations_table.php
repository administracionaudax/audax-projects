<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canales del chat (D-270 a D-272): el canal de cada cliente (uno por cliente, client_id) y los
     * canales de equipo (con nombre, emoji y archivado). Los de equipo y de cliente se ven por regla
     * (la plantilla; los colaboradores, D-134, solo en su alcance), no solo por participar.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->unique()->after('project_id')->constrained()->cascadeOnDelete();
            $table->string('icon', 16)->nullable()->after('name');
            $table->timestamp('archived_at')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['icon', 'archived_at']);
        });
    }
};
