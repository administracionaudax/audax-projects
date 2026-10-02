<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avisos del chat (D-115): el mensaje del que habla cada aviso, para vaciar su extracto en la
     * campana en cuanto el mensaje se oculta o se borra (una consulta simple e indexada, igual en
     * PostgreSQL y SQLite; `data` es texto JSON y no se puede filtrar igual en las dos).
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_message_id')->nullable()->after('notifiable_id');
            $table->index('chat_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['chat_message_id']);
            $table->dropColumn('chat_message_id');
        });
    }
};
