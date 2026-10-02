<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat (Fase 6, área C1):
     * - link_preview: previsualización del primer enlace del mensaje (D-069): título, descripción,
     *   dominio y URL, sin imagen remota. La rellena un job con protección SSRF.
     * - índice (conversation_id, pinned_at): la barra de fijados se consulta al abrir la
     *   conversación y en cada consulta periódica, sin recorrer todos sus mensajes.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('link_preview')->nullable();
            $table->index(['conversation_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'pinned_at']);
            $table->dropColumn('link_preview');
        });
    }
};
